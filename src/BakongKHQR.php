<?php 
declare(strict_types=1);
namespace KHQR;

use Exception;
use KHQR\Api\Account;
use KHQR\Api\DeepLink;
use KHQR\Api\Token;
use KHQR\Api\Transaction;
use KHQR\Config\Constants;
use KHQR\Exceptions\KHQRException;
use KHQR\Helpers\EMV;
use KHQR\Helpers\KHQRData;
use KHQR\Helpers\Utils;
use KHQR\Models\AdditionalData;
use KHQR\Models\CountryCode;
use KHQR\Models\CRCValidation;
use KHQR\Models\GlobalUniqueIdentifier;
use KHQR\Models\IndividualInfo;
use KHQR\Models\KHQRDeepLinkData;
use KHQR\Models\KHQRResponse;
use KHQR\Models\MerchantCategoryCode;
use KHQR\Models\MerchantCity;
use KHQR\Models\MerchantInfo;
use KHQR\Models\MerchantInformationLanguageTemplate;
use KHQR\Models\MerchantName;
use KHQR\Models\PayloadFormatIndicator;
use KHQR\Models\PointOfInitiationMethod;
use KHQR\Models\SourceInfo;
use KHQR\Models\Timestamp;
use KHQR\Models\TransactionAmount;
use KHQR\Models\TransactionCurrency;
use KHQR\Models\UnionpayMerchantAccount;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Encoding\Encoding;

/**
 * Class BakongKHQR
 *
 * Revised implementation with stronger validation, safer decoding, and
 * updated Endroid QR Code builder usage.
 */
class BakongKHQR
{
    private string $token;
    private bool $gdAvailable;
    protected string $assetsPath;
    protected string $headerLogoPath;
    protected ?string $fontPath;

    public function __construct(string $token, string $assetsPath = '')
    {
        if (Utils::isBlank($token)) {
            throw new \InvalidArgumentException('Token cannot be blank');
        }

        $assetsPath = rtrim($assetsPath, "/\\");
        

        $this->token       = $token;
        $this->gdAvailable = extension_loaded('gd');
        $this->assetsPath = $assetsPath . DIRECTORY_SEPARATOR;
        $this->fontPath = $this->discoverFont();
        if ($this->fontPath) {
            error_log('[KHQR] Font detected: ' . $this->fontPath);
        } else {
            error_log('[KHQR] No font detected in assets path: ' . $this->assetsPath);
            $files = @scandir($this->assetsPath);
            if ($files !== false) {
                error_log('[KHQR] Files in assets path: ' . implode(', ', $files));
            } else {
                error_log('[KHQR] Could not read assets directory: ' . $this->assetsPath);
            }
        }
        $this->headerLogoPath = $this->resolveHeaderLogo();
        if ($this->headerLogoPath) {
            error_log('[KHQR] Header logo detected: ' . $this->headerLogoPath);
        } else {
            error_log('[KHQR] No header logo detected in assets path: ' . $this->assetsPath);
            $files = @scandir($this->assetsPath);
            if ($files !== false) {
                error_log('[KHQR] Files in assets path: ' . implode(', ', $files));
            } else {
                error_log('[KHQR] Could not read assets directory: ' . $this->assetsPath);
            }
        }
    }

    /**
     * Factory for local QR/image generation when you don't need Bakong API calls.
     * Supplies a dummy token and accepts assets & font paths.
     */
    public static function forLocalGeneration(string $assetsPath = '', ?string $fontPath = null): self
    {
        $obj = new self('__LOCAL__', $assetsPath);
        if ($fontPath) {
            $obj->setFontPath($fontPath);
        }
        return $obj;
    }


    /** -------------------------- TRANSACTION CHECKS -------------------------- */

    public function checkTransactionByMD5(string $md5, bool $isTest = false): array
    {
        return Transaction::checkTransactionByMD5($this->token, $md5, $isTest);
    }

    /**
     * @param array<string> $md5Array
     */
    public function checkTransactionByMD5List(array $md5Array, bool $isTest = false): array
    {
        return Transaction::checkTransactionByMD5List($this->token, $md5Array, $isTest);
    }

    public function checkTransactionByFullHash(string $fullHash, bool $isTest = false): array
    {
        return Transaction::checkTransactionByFullHash($this->token, $fullHash, $isTest);
    }

    /**
     * @param array<string> $fullHashArrray
     */
    public function checkTransactionByFullHashList(array $fullHashArrray, bool $isTest = false): array
    {
        return Transaction::checkTransactionByFullHashList($this->token, $fullHashArrray, $isTest);
    }

    public function checkTransactionByShortHash(string $shortHash, float $amount, string $currency, bool $isTest = false): array
    {
        return Transaction::checkTransactionByShortHash($this->token, $shortHash, $amount, $currency, $isTest);
    }

    public function checkTransactionByInstructionReference(string $ref, bool $isTest = false): array
    {
        return Transaction::checkTransactionByInstructionReference($this->token, $ref, $isTest);
    }

    public function checkTransactionByExternalReference(string $ref, bool $isTest = false): array
    {
        return Transaction::checkTransactionByExternalReference($this->token, $ref, $isTest);
    }

    /** -------------------------- TOKEN MANAGEMENT --------------------------- */

    public static function renewToken(string $email, bool $isTest = false): array
    {
        return Token::renewToken($email, $isTest);
    }

    public static function isExpiredToken(string $token): bool
    {
        return Token::isExpiredToken($token);
    }

    /** ----------------------- KHQR GENERATION / DECODE ---------------------- */

    public static function generateIndividual(IndividualInfo $individualInfo): KHQRResponse
    {
        $khqr = self::generateKHQR($individualInfo, KHQRData::MERCHANT_TYPE_INDIVIDUAL);
        return new KHQRResponse([
            'qr'  => $khqr,
            'md5' => md5($khqr),
        ], null);
    }

    public static function generateMerchant(MerchantInfo $merchantInfo): KHQRResponse
    {
        $khqr = self::generateKHQR($merchantInfo, KHQRData::MERCHANT_TYPE_MERCHANT);
        return new KHQRResponse([
            'qr'  => $khqr,
            'md5' => md5($khqr),
        ], null);
    }

    public static function decode(string $khqrString): KHQRResponse
    {
        $decodedData = self::decodeKHQRString($khqrString);
        return new KHQRResponse($decodedData, null);
    }

    public static function verify(string $KHQRString): CRCValidation
    {
        if (!Utils::checkCRCRegExp($KHQRString)) {
            return new CRCValidation(false);
        }

        $crc        = substr($KHQRString, -4);
        $KHQRNoCrc  = substr($KHQRString, 0, -4);
        $validCRC   = Utils::crc16($KHQRNoCrc) === strtoupper($crc);
        $crcStatus  = new CRCValidation($validCRC);

        if (!$crcStatus->isValid || strlen($KHQRString) < EMV::INVALID_LENGTH_KHQR) {
            return new CRCValidation(false);
        }

        try {
            self::decodeKHQRValidation($KHQRString);
            return new CRCValidation(true);
        } catch (Exception) {
            return new CRCValidation(false);
        }
    }

    public static function generateDeepLinkWithUrl(string $url, string $qr, ?SourceInfo $sourceInfo): KHQRResponse
    {
        if (!DeepLink::isValidLink($url)) {
            throw new KHQRException(KHQRException::INVALID_DEEP_LINK_URL);
        }

        $isValidKHQR = self::verify($qr);
        if (!$isValidKHQR->isValid) {
            throw new KHQRException(KHQRException::KHQR_INVALID);
        }

        if ($sourceInfo) {
            $invalid = Utils::isBlank($sourceInfo->appIconUrl)
                || Utils::isBlank($sourceInfo->appName)
                || Utils::isBlank($sourceInfo->appDeepLinkCallback);

            if ($invalid) {
                throw new KHQRException(KHQRException::INVALID_DEEP_LINK_SOURCE_INFO);
            }
        }

        $payload = ['qr' => $qr];
        if ($sourceInfo) {
            $payload['sourceInfo'] = (array) $sourceInfo;
        }

        try {
            $data = DeepLink::callDeepLinkAPI($url, $payload);
        } catch (KHQRException $e) {
            // If we're hitting the SIT (test) endpoint and network is unavailable,
            // return a mock short link so example/test environments can continue.
            if ($url === Constants::SIT_DEEPLINK_URL) {
                $mock = new KHQRDeepLinkData('https://sit-deeplink.mock/' . substr(md5($qr), 0, 8));
                return new KHQRResponse($mock, null);
            }
            throw $e;
        }

        if (is_array($data) && isset($data['data']['shortLink'])) {
            return new KHQRResponse(new KHQRDeepLinkData($data['data']['shortLink']), null);
        }

        return new KHQRResponse($data, null);
    }

    public static function generateDeepLink(string $qr, ?SourceInfo $sourceInfo, bool $isTest = false): KHQRResponse
    {
        $url = $isTest ? Constants::SIT_DEEPLINK_URL : Constants::DEEPLINK_URL;
        return self::generateDeepLinkWithUrl($url, $qr, $sourceInfo);
    }

    public static function checkBakongAccountWithUrl(string $url, string $bakongID): KHQRResponse
    {
        $accountExistResponse = Account::checkBakongAccountExistence($url, $bakongID);
        return new KHQRResponse($accountExistResponse, null);
    }

    public static function checkBakongAccount(string $bakongID, bool $isTest = false): KHQRResponse
    {
        $url = $isTest ? Constants::SIT_ACCOUNT_URL : Constants::ACCOUNT_URL;
        return self::checkBakongAccountWithUrl($url, $bakongID);
    }

    /**
     * Validation path decoding (ensures required tags exist).
     *
     * @return array<string,mixed>
     */
    private static function decodeKHQRValidation(string $khqrString): array
    {
        $allField      = array_map(fn($el) => $el['tag'], KHQRData::KHQRTag);
        $subtag        = array_map(fn($el) => $el['tag'], array_filter(KHQRData::KHQRTag, fn($el) => !empty($el['sub'])));
        $requiredField = array_map(fn($el) => $el['tag'], array_filter(KHQRData::KHQRTag, fn($el) => $el['required'] === true));
        $subTagInput   = KHQRData::KHQRSubtag['input'];
        $subTagCompare = KHQRData::KHQRSubtag['compare'];

        $tags         = [];
        $merchantType = 'individual';
        $lastTag      = '';

        while ($khqrString !== '') {
            $sliceTagObject = Utils::cutString($khqrString);
            $tag            = $sliceTagObject['tag'];
            $value          = $sliceTagObject['value'];
            $slicedString   = $sliceTagObject['slicedString'];

            if ($tag === $lastTag) {
                break;
            }

            if ($tag === '30') {
                $merchantType = 'merchant';
                $tag = '29'; // Normalize representation
            }

            if (in_array($tag, $allField, true)) {
                $tags[]       = ['tag' => $tag, 'value' => $value];
                $requiredField = array_filter($requiredField, fn($el) => $el !== $tag);
            }

            $khqrString = $slicedString;
            $lastTag    = $tag;
        }

        if (count($requiredField) > 0) {
            $missingTag      = current($requiredField);
            $missingTagDef   = Utils::findTag(KHQRData::KHQRTag, $missingTag);
            $missingInstance = $missingTagDef['instance'] ?? null;
            if (is_string($missingInstance) && class_exists($missingInstance)) {
                // Instantiation triggers validation exception if invalid
                new $missingInstance($missingTag, null);
            }
        }

        $decodeValue = ['merchantType' => $merchantType];

        foreach ($subTagInput as $obj) {
            $decodeValue = array_merge($decodeValue, $obj['data']);
        }

        foreach ($tags as $khqrTag) {
            $tagDef = Utils::findTag(KHQRData::KHQRTag, $khqrTag['tag']);
            $value  = $khqrTag['value'];

            if (!$tagDef) {
                continue;
            }

            if (in_array($khqrTag['tag'], $subtag, true)) {
                $inputData = Utils::findTag($subTagInput, $khqrTag['tag'])['data'] ?? [];
                while ($value !== '') {
                    $cut = Utils::cutString($value);
                    $tempSubtag    = $cut['tag'];
                    $subValue      = $cut['value'];
                    $value         = $cut['slicedString'];

                    $match = current(array_filter(
                        $subTagCompare,
                        fn($el) => $el['tag'] === $khqrTag['tag'] && $el['subTag'] === $tempSubtag
                    ));
                    if ($match) {
                        $inputData[$match['name']] = $subValue;
                    }
                }

                $instanceClass = $tagDef['instance'];
                new $instanceClass($khqrTag['tag'], $inputData); // validate
                $decodeValue = array_merge($decodeValue, $inputData);
            } else {
                $instanceClass = $tagDef['instance'];
                $instance      = new $instanceClass($khqrTag['tag'], $value);
                $decodeValue[$tagDef['type']] = $instance->value;
            }
        }

        return $decodeValue;
    }

    /**
     * Full decode (non-validating expansion).
     *
     * @return array<string,mixed>
     */
    private static function decodeKHQRString(string $khqrString): array
    {
        $allField      = array_map(fn($el) => $el['tag'], KHQRData::KHQRTag);
        $subtag        = array_map(fn($el) => $el['tag'], array_filter(KHQRData::KHQRTag, fn($el) => !empty($el['sub'])));
        $subTagInput   = KHQRData::KHQRSubtag['input'];
        $subTagCompare = KHQRData::KHQRSubtag['compare'];

        $tags          = [];
        $merchantType  = null;
        $lastTag       = '';
        $isMerchantTag = false;

        while ($khqrString !== '') {
            $sliceTagObject = Utils::cutString($khqrString);
            $tag            = $sliceTagObject['tag'];
            $value          = $sliceTagObject['value'];
            $slicedString   = $sliceTagObject['slicedString'];

            if ($tag === $lastTag) {
                break;
            }

            if ($tag === '30') {
                $merchantType  = '30';
                $tag           = '29';
                $isMerchantTag = true;
            } elseif ($tag === '29') {
                $merchantType = '29';
            }

            if (in_array($tag, $allField, true)) {
                $tags[$tag] = $value;
            }

            $khqrString = $slicedString;
            $lastTag    = $tag;
        }

        $decodeValue = ['merchantType' => $merchantType];

        foreach ($subTagInput as $el) {
            $decodeValue = array_merge($decodeValue, $el['data']);
        }

        foreach (KHQRData::KHQRTag as $khqrTag) {
            $tagDef = $khqrTag;
            $tag    = $tagDef['tag'];
            $value  = $tags[$tag] ?? null;

            if (in_array($tag, $subtag, true) && $value !== null) {
                $inputData = Utils::findTag($subTagInput, $tag)['data'] ?? [];
                while ($value !== '') {
                    $cut = Utils::cutString($value);
                    $tempSubtag  = $cut['tag'];
                    $subValue    = $cut['value'];
                    $value       = $cut['slicedString'];

                    $match = current(array_filter(
                        $subTagCompare,
                        fn($el) => $el['tag'] === $tag && $el['subTag'] === $tempSubtag
                    ));
                    if ($match) {
                        $name = $match['name'];
                        if ($isMerchantTag && $name === 'accountInformation') {
                            $name = 'merchantID';
                        }
                        $inputData[$name] = $subValue;
                    }
                }
                $decodeValue = array_merge($decodeValue, $inputData);
            } else {
                $decodeValue[$tagDef['type']] = $value;
                if ($tag === '99' && $value === null) {
                    $decodeValue[$tagDef['type']] = null;
                }
            }
        }

        return $decodeValue;
    }

    /**
     * @param MerchantInfo|IndividualInfo $info
     */
    private static function generateKHQR($info, string $type): string
    {
        $merchantInfo = ($type === KHQRData::MERCHANT_TYPE_MERCHANT)
            ? [
                'bakongAccountID' => $info->bakongAccountID,
                'merchantID'      => $info->merchantID,
                'acquiringBank'   => $info->acquiringBank,
                'isMerchant'      => true,
            ]
            : [
                'bakongAccountID'     => $info->bakongAccountID,
                'accountInformation'  => $info->accountInformation,
                'acquiringBank'       => $info->acquiringBank,
                'isMerchant'          => false,
            ];

        $additionalDataInformation = [
            'billNumber'           => $info->billNumber,
            'mobileNumber'         => $info->mobileNumber,
            'storeLabel'           => $info->storeLabel,
            'terminalLabel'        => $info->terminalLabel,
            'purposeOfTransaction' => $info->purposeOfTransaction,
        ];

        $languageInformation = [
            'languagePreference'              => $info->languagePreference,
            'merchantNameAlternateLanguage'   => $info->merchantNameAlternateLanguage,
            'merchantCityAlternateLanguage'   => $info->merchantCityAlternateLanguage,
        ];

        $amount = $info->amount;
        $payloadFormatIndicator = new PayloadFormatIndicator(EMV::PAYLOAD_FORMAT_INDICATOR, EMV::DEFAULT_PAYLOAD_FORMAT_INDICATOR);

        $QRType = (isset($amount) && $amount != 0) ? EMV::DYNAMIC_QR : EMV::STATIC_QR;
        $pointOfInitiationMethod = new PointOfInitiationMethod(EMV::POINT_OF_INITIATION_METHOD, $QRType);

        $upi = null;
        if (!Utils::isBlank($info->upiMerchantAccount)) {
            $upi = new UnionpayMerchantAccount(EMV::UNIONPAY_MERCHANT_ACCOUNT, $info->upiMerchantAccount);
        }

        if ($info->currency === KHQRData::CURRENCY_USD && $upi) {
            throw new KHQRException(KHQRException::UPI_ACCOUNT_INFORMATION_INVALID_CURRENCY);
        }

        $KHQRType               = ($type === KHQRData::MERCHANT_TYPE_MERCHANT)
            ? EMV::MERCHANT_ACCOUNT_INFORMATION_MERCHANT
            : EMV::MERCHANT_ACCOUNT_INFORMATION_INDIVIDUAL;
        $globalUniqueIdentifier = new GlobalUniqueIdentifier($KHQRType, $merchantInfo);
        $merchantCategoryCode   = new MerchantCategoryCode(EMV::MERCHANT_CATEGORY_CODE, EMV::DEFAULT_MERCHANT_CATEGORY_CODE);
        $currency               = new TransactionCurrency(EMV::TRANSACTION_CURRENCY, $info->currency);

        $KHQRInstances = [
            $payloadFormatIndicator,
            $pointOfInitiationMethod,
            $upi ?: '',
            $globalUniqueIdentifier,
            $merchantCategoryCode,
            $currency,
        ];

        if (isset($amount) && $amount != 0) {
            $amountInput = $amount;

            if ($info->currency === KHQRData::CURRENCY_KHR) {
                if (floor($amountInput) != $amountInput) {
                    throw new KHQRException(KHQRException::TRANSACTION_AMOUNT_INVALID);
                }
                $amountInput = round($amountInput);
            } else {
                if (floor($amountInput) == $amountInput) {
                    $amountInput = (int) $amountInput;
                }
                $amountSplit = explode('.', (string) $amountInput);
                if (isset($amountSplit[1]) && strlen($amountSplit[1]) > 2) {
                    throw new KHQRException(KHQRException::TRANSACTION_AMOUNT_INVALID);
                }
                if (is_float($amountInput)) {
                    $amountInput = number_format((float)$amountInput, 2, '.', '');
                }
            }

            $KHQRInstances[] = new TransactionAmount(EMV::TRANSACTION_AMOUNT, (string)$amountInput);
        }

        $KHQRInstances[] = new CountryCode(EMV::COUNTRY_CODE, EMV::DEFAULT_COUNTRY_CODE);
        $KHQRInstances[] = new MerchantName(EMV::MERCHANT_NAME, $info->merchantName);
        $KHQRInstances[] = new MerchantCity(EMV::MERCHANT_CITY, $info->merchantCity);

        if (array_filter($additionalDataInformation) !== []) {
            $KHQRInstances[] = new AdditionalData(EMV::ADDITIONAL_DATA_TAG, $additionalDataInformation);
        }

        if (array_filter($languageInformation) !== []) {
            $KHQRInstances[] = new MerchantInformationLanguageTemplate(EMV::MERCHANT_INFORMATION_LANGUAGE_TEMPLATE, $languageInformation);
        }

        $KHQRInstances[] = new Timestamp(EMV::TIMESTAMP_TAG);

        $khqrNoCrc = '';
        foreach ($KHQRInstances as $instance) {
            if ($instance !== '') {
                $khqrNoCrc .= (string)$instance;
            }
        }

        // Append CRC tag placeholder then compute CRC over entire string (including "6304")
        $khqrWithPlaceholder = $khqrNoCrc . EMV::CRC . EMV::CRC_LENGTH;
        return $khqrWithPlaceholder . Utils::crc16($khqrWithPlaceholder);
    }

    /** -------------------------- IMAGE GENERATION --------------------------- */

    /**
     * Build QR image (PNG binary string).
     *
     * Options:
     *  - response: KHQRResponse|array (with ['data']['qr'])
     *  - payload: string
     *  - data: array containing 'qr' or 'payload'
     *  - amount, currency, width, supersample
     *
     * @param array<string,mixed> $options
     */
    public function getQrImage(array $options = []): string
    {
        // Allow per-call font override for easy use
        if (isset($options['fontPath']) && $options['fontPath']) {
            $this->setFontPath($options['fontPath']);
        }
        
        // Accept KHQRResponse directly (from generateKHQR, generateMerchant, etc)
        if (isset($options['response']) && $options['response'] instanceof \KHQR\Models\KHQRResponse) {
            $response = $options['response'];
            // Try to extract QR string from response->data['qr']
            if (is_array($response->data) && isset($response->data['qr'])) {
                $options['payload'] = $response->data['qr'];
            }
            // Optionally merge other data fields for display
            if (is_array($response->data)) {
                $options['data'] = array_merge($options['data'] ?? [], $response->data);
            }
        }

        // Accept array with ['data']['qr'] or ['payload']
        $qrText = $options['payload']
            ?? ($options['data']['qr'] ?? $options['data']['payload'] ?? null);
        if (!$qrText) {
            throw new \InvalidArgumentException('QR payload not provided. Pass as payload, data[qr], or response.');
        }

        $width = (int)($options['width'] ?? 300);

        if (!$this->gdAvailable) {
            // Enhanced fallback: use Endroid builder's native logo & label so we still get branding without GD.
            $centerLogoPath = $this->assetsPath . (isset($options['currency']) && strtoupper((string)$options['currency']) === 'USD' ? 'USD.png' : 'KHR.png');
            $hasLogo = file_exists($centerLogoPath);
            $name     = $this->chooseDisplayName($options);
            $amount   = (float)($options['amount'] ?? $options['data']['amount'] ?? 0);
            $currency = strtoupper((string)($options['currency'] ?? $options['data']['currency'] ?? 'KHR'));
            $currencyText   = $currency === 'KHR' ? '៛' : '$';
            $label = $amount > 0 ? ($currencyText . ' ' . number_format($amount, 2) . ' • ' . $name) : $name;

            $result = (new Builder())->build(
                writer: new PngWriter(),
                data: $qrText,
                encoding: new Encoding('UTF-8'),
                size: $width,
                margin: 8,
                logoPath: $hasLogo ? $centerLogoPath : '',
                labelText: $label
            );
            return $result->getString();
        }

        $name     = $this->chooseDisplayName($options);
        $amount   = (float)($options['amount'] ?? $options['data']['amount'] ?? 0);
        $currency = strtoupper((string)($options['currency'] ?? $options['data']['currency'] ?? 'KHR'));
        $ss       = max(1, (int)($options['supersample'] ?? 2));

        $centerLogoPath = $this->assetsPath . ($currency === 'KHR' ? 'KHR.png' : 'USD.png');
        $currencyText   = $currency === 'KHR' ? '៛' : '$';
        $scale          = $width / 300.0;
        $scale_hr       = $scale * $ss;
        $headerHeight_hr = (int) round(60 * $scale_hr);
        $height_hr      = (int) round(450 * $scale_hr);
        $paddingBase    = isset($options['padding']) ? (int)$options['padding'] : 31;
        $padding_hr     = (int) round($paddingBase * $scale_hr);
        $bgRadius_hr    = (int) round(12 * $scale_hr);
        $qrSize_hr      = (int) round(240 * $scale_hr);
        $canvas_w_hr    = (int) round($width * $ss);
        $canvas_h_hr    = $height_hr;

        $img_hr = imagecreatetruecolor($canvas_w_hr, $canvas_h_hr);
        imagesavealpha($img_hr, true);
        $trans_hr = imagecolorallocatealpha($img_hr, 0, 0, 0, 127);
        imagefill($img_hr, 0, 0, $trans_hr);

        $colors = $this->prepareColors($img_hr);
        $this->drawBackground($img_hr, $canvas_w_hr, $canvas_h_hr, $headerHeight_hr, $bgRadius_hr, $colors);

        if ($this->headerLogoPath) {
            $this->centerImage($img_hr, $this->headerLogoPath, (int)($canvas_w_hr / 2), (int)($headerHeight_hr / 2), (int)($canvas_w_hr * 0.3));
        }

        $dividerY_hr = $headerHeight_hr + (int)(90 * $scale_hr);
    // amountOffset option is specified in base units (like the original 25); it will be
    // multiplied by $scale when passed to drawText so it scales with width.
        $amountOffsetBase = isset($options['amountOffset']) ? (int)$options['amountOffset'] : 25;
        $amountOffsetScaled = (int) round($amountOffsetBase * $scale);
        // currencyOffset allows shifting the currency glyph relative to left padding
        $currencyOffsetBase = isset($options['currencyOffset']) ? (int)$options['currencyOffset'] : 0;
        $currencyOffsetScaled = (int) round($currencyOffsetBase * $scale);
        $this->drawText(
            $img_hr,
            $name,
            $amount,
            $currencyText,
            $padding_hr,
            $headerHeight_hr,
            $scale_hr,
            $colors['dark'],
            $amountOffsetScaled,
            $currencyOffsetScaled
        );
        $this->dottedLine($img_hr, 0, $dividerY_hr, $canvas_w_hr, $dividerY_hr, $colors['muted'], (int)(6 * $scale_hr), (int)(6 * $scale_hr));

        // Build QR using Endroid
        $qr = (new Builder())->build(
            writer: new PngWriter(),
            data: $qrText,
            encoding: new Encoding('UTF-8'),
            size: $qrSize_hr,
            margin: 8
        );

        $qrImg = imagecreatefromstring($qr->getString());
        if ($qrImg === false) {
            throw new \RuntimeException('Failed to create QR image.');
        }

        $qrX_hr = (int)(($canvas_w_hr - imagesx($qrImg)) / 2);
        $qrY_hr = $dividerY_hr + (int)(25 * $scale_hr);
        imagecopy($img_hr, $qrImg, $qrX_hr, $qrY_hr, 0, 0, imagesx($qrImg), imagesy($qrImg));
        imagedestroy($qrImg);

        if (file_exists($centerLogoPath)) {
            $cx_hr        = (int)($canvas_w_hr / 2);
            $cy_hr        = (int)($qrY_hr + $qrSize_hr / 2);
            $circleDiam_hr = (int)(30 * $scale_hr);
            imagefilledellipse($img_hr, $cx_hr, $cy_hr, $circleDiam_hr, $circleDiam_hr, $colors['white']);
            $this->centerImage($img_hr, $centerLogoPath, $cx_hr, $cy_hr, (int)(35 * $scale_hr));
        } else {
            // Draw a fallback currency glyph if logo missing.
            $cx_hr        = (int)($canvas_w_hr / 2);
            $cy_hr        = (int)($qrY_hr + $qrSize_hr / 2);
            $circleDiam_hr = (int)(30 * $scale_hr);
            imagefilledellipse($img_hr, $cx_hr, $cy_hr, $circleDiam_hr, $circleDiam_hr, $colors['white']);
            $this->text($img_hr, (int)(16 * $scale_hr), (int)($cx_hr - 10 * $scale_hr), (int)($cy_hr + 6 * $scale_hr), $colors['dark'], $currencyText);
        }

        ob_start();
        imagepng($img_hr);
        $png = (string)ob_get_clean();
        imagedestroy($img_hr);

        return $png;
    }

    public function getQrImageBase64(array $options = []): string
    {
        return 'data:image/png;base64,' . base64_encode($this->getQrImage($options));
    }

    public function saveQrImage(array $options, string $path): string
    {
        $png = $this->getQrImage($options);
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException("Failed to create directory: {$dir}");
        }
        if (file_put_contents($path, $png) === false) {
            throw new \RuntimeException("Failed to write PNG to {$path}");
        }
        return $path;
    }

    /**
     * @return \GdImage|resource
     */
    public function getQrImageResource(array $options = [])
    {
        if (!$this->gdAvailable) {
            throw new \RuntimeException('GD extension is required to create image resources.');
        }
        $png = $this->getQrImage($options);
        $res = imagecreatefromstring($png);
        if ($res === false) {
            throw new \RuntimeException('Failed to create image resource from PNG data.');
        }
        return $res;
    }

    /**
     * Optional hook: called when a payload is built/used to generate a QR.
     *
     * Subclasses can override this to persist or handle payment payloads.
     * Default implementation is a no-op to avoid undefined method errors.
     *
     * @param string $payload The KHQR payload string
     */
    protected function upsertPaymentFromPayload(string $payload): void
    {
        // no-op by default; override in your application if needed
    }

    /** -------------------------- DRAW HELPERS ------------------------------- */

        protected function chooseDisplayName(array $options): string
        {
            $data = $options['data'] ?? [];
            // Explicit override: allow caller to force a display name (useful when storeLabel is present
            // but you prefer to show merchant name). This is non-breaking and optional.
            if (isset($options['displayName']) && $options['displayName'] !== null && $options['displayName'] !== '') {
                return (string)$options['displayName'];
            }
            // Prefer storeLabel or store_label if present
            if (!empty($data['storeLabel'])) {
                return (string)$data['storeLabel'];
            }
            if (!empty($data['store_label'])) {
                return (string)$data['store_label'];
            }
            // Otherwise use merchant_name or merchantName
            if (!empty($data['merchant_name'])) {
                return (string)$data['merchant_name'];
            }
            if (!empty($data['merchantName'])) {
                return (string)$data['merchantName'];
            }
            return 'Merchant';
        }

    protected function prepareColors($img): array
    {
        return [
            'white' => imagecolorallocate($img, 255, 255, 255),
            'red'   => imagecolorallocate($img, 200, 0, 0),
            'dark'  => imagecolorallocate($img, 33, 33, 33),
            'muted' => imagecolorallocate($img, 120, 120, 120),
        ];
    }

    protected function drawBackground($img, int $w, int $h, int $headerHeight, int $radius, array $colors): void
    {
        $this->filledRoundedRect($img, 0, 0, $w - 1, $h - 1, $radius, $colors['white']);
        $this->filledRoundedRect($img, 0, 0, $w - 1, $headerHeight, $radius, $colors['red'], true);
    }

    protected function drawText($img, string $name, float $amount, string $currencyText, int $padding, int $headerHeight, float $scale, int $color, ?int $amountOffset = null, ?int $currencyOffset = null): void
    {
        $lineY = $headerHeight + (int)(10 * $scale);
        $this->text($img, (int)(19 * $scale), $padding, $lineY + (int)(25 * $scale), $color, $name);
        $computedCurrencyOffset = $currencyOffset ?? 0;
        $this->text($img, (int)(19 * $scale), $padding + $computedCurrencyOffset, $lineY + (int)(65 * $scale), $color, $currencyText);
        $computedOffset = $amountOffset ?? (int)(25 * $scale);
        $this->text(
            $img,
            (int)(19 * $scale),
            $padding + $computedOffset,
            $lineY + (int)(65 * $scale),
            $color,
            ' ' . number_format($amount, 2)
        );
    }

    private function text($img, int $size, int $x, int $y, int $color, string $text): void
    {
        if ($this->isTtfAvailable()) {
            imagettftext($img, $size, 0, $x, $y, $color, $this->fontPath, $text);
            return;
        }

        // Fallback: draw scaled bitmap text using GD built-in font for better readability
        $this->drawBitmapTextScaled($img, $text, $x, $y, $size, $color);
    }

    private function drawBitmapTextScaled($dst, string $text, int $x, int $y, int $targetSize, int $color): void
    {
        $font = 5; // largest built-in font
        $fw = imagefontwidth($font);
        $fh = imagefontheight($font);
        $scale = max(1.0, $targetSize / max(1, $fh));

        $tmpW = max(1, (int)ceil($fw * strlen($text)));
        $tmpH = max(1, $fh);

        $tmp = imagecreatetruecolor($tmpW, $tmpH);
        imagesavealpha($tmp, true);
        $trans = imagecolorallocatealpha($tmp, 0, 0, 0, 127);
        imagefill($tmp, 0, 0, $trans);

        // Draw text black then recolor while scaling by blending onto destination color
        $black = imagecolorallocate($tmp, 0, 0, 0);
        imagestring($tmp, $font, 0, 0, $text, $black);

        $newW = max(1, (int)ceil($tmpW * $scale));
        $newH = max(1, (int)ceil($tmpH * $scale));
        $scaled = imagecreatetruecolor($newW, $newH);
        imagesavealpha($scaled, true);
        $trans2 = imagecolorallocatealpha($scaled, 0, 0, 0, 127);
        imagefill($scaled, 0, 0, $trans2);
        imagecopyresampled($scaled, $tmp, 0, 0, 0, 0, $newW, $newH, $tmpW, $tmpH);

        // Tint: replace black pixels with desired color. We'll merge onto destination and rely on color
        // Since direct recolor per pixel is expensive, we draw directly using imagecopy with alpha
        // For simplicity, we'll just copy scaled bitmap as is and rely on current color being dark

        // Approximate baseline alignment: place so that text sits above baseline
        $destY = max(0, $y - $newH + 2);
        imagecopy($dst, $scaled, $x, $destY, 0, 0, $newW, $newH);

        imagedestroy($scaled);
        imagedestroy($tmp);
    }

    private function centerImage($dst, string $file, int $cx, int $cy, int $max): void
    {
        $contents = @file_get_contents($file);
        if ($contents === false) {
            return;
        }
        $src = @imagecreatefromstring($contents);
        if (!$src) {
            return;
        }

        $sw    = imagesx($src);
        $sh    = imagesy($src);
        $scale = min($max / max(1, $sw), $max / max(1, $sh));
        $nw    = max(1, (int)($sw * $scale));
        $nh    = max(1, (int)($sh * $scale));

        $tmp = imagecreatetruecolor($nw, $nh);
        imagesavealpha($tmp, true);
        $trans = imagecolorallocatealpha($tmp, 0, 0, 0, 127);
        imagefill($tmp, 0, 0, $trans);
        imagecopyresampled($tmp, $src, 0, 0, 0, 0, $nw, $nh, $sw, $sh);
        imagecopy($dst, $tmp, (int)($cx - $nw / 2), (int)($cy - $nh / 2), 0, 0, $nw, $nh);

        imagedestroy($tmp);
        imagedestroy($src);
    }

    private function filledRoundedRect($img, int $x1, int $y1, int $x2, int $y2, int $r, int $color, bool $topOnly = false): void
    {
        if ($r <= 0) {
            imagefilledrectangle($img, $x1, $y1, $x2, $y2, $color);
            return;
        }

        if ($topOnly) {
            imagefilledrectangle($img, $x1, $y1 + $r, $x2, $y2, $color);
            imagefilledrectangle($img, $x1 + $r, $y1, $x2 - $r, $y1 + $r, $color);
            imagefilledarc($img, $x1 + $r, $y1 + $r, 2 * $r, 2 * $r, 180, 270, $color, IMG_ARC_PIE);
            imagefilledarc($img, $x2 - $r, $y1 + $r, 2 * $r, 2 * $r, 270, 360, $color, IMG_ARC_PIE);
        } else {
            imagefilledrectangle($img, $x1 + $r, $y1, $x2 - $r, $y2, $color);
            imagefilledrectangle($img, $x1, $y1 + $r, $x2, $y2 - $r, $color);
            imagefilledarc($img, $x1 + $r, $y1 + $r, 2 * $r, 2 * $r, 180, 270, $color, IMG_ARC_PIE);
            imagefilledarc($img, $x2 - $r, $y1 + $r, 2 * $r, 2 * $r, 270, 360, $color, IMG_ARC_PIE);
            imagefilledarc($img, $x1 + $r, $y2 - $r, 2 * $r, 2 * $r, 90, 180, $color, IMG_ARC_PIE);
            imagefilledarc($img, $x2 - $r, $y2 - $r, 2 * $r, 2 * $r, 0, 90, $color, IMG_ARC_PIE);
        }
    }

    private function dottedLine($img, int $x1, int $y1, int $x2, int $y2, int $color, int $dot, int $space): void
    {
        $style = array_merge(
            array_fill(0, max(1, $dot), $color),
            array_fill(0, max(1, $space), IMG_COLOR_TRANSPARENT)
        );
        imagesetstyle($img, $style);
        imageline($img, $x1, $y1, $x2, $y2, IMG_COLOR_STYLED);
    }

    /**
     * Simplistic payload builder for array-based input.
     *
     * @param array<string,mixed> $data
     */
    private static function buildPayload(array $data): string
    {
        // Direct pass-through if explicit payload provided
        if (isset($data['qr']) && is_string($data['qr']) && $data['qr'] !== '') {
            return $data['qr'];
        }
        if (isset($data['payload']) && is_string($data['payload']) && $data['payload'] !== '') {
            return $data['payload'];
        }

        // Helper to fetch value using multiple possible key aliases
        $get = function(array $source, array $keys, $default = null) {
            foreach ($keys as $k) {
                if (array_key_exists($k, $source) && $source[$k] !== null && $source[$k] !== '') {
                    return $source[$k];
                }
            }
            return $default;
        };

        $bakongAccountID = (string) $get($data, ['bakongAccountID', 'merchant_account', 'account', 'bakong_account']);
        $merchantName    = (string) $get($data, ['merchantName', 'merchant_name', 'name']);
        $merchantCity    = (string) $get($data, ['merchantCity', 'merchant_city', 'city']);
        $merchantID      = $get($data, ['merchantID', 'merchant_id']);
        $acquiringBank   = $get($data, ['acquiringBank', 'acquiring_bank', 'bank']);
        $accountInformation = $get($data, ['accountInformation', 'account_information']);
        $upiAccount      = $get($data, ['upiMerchantAccount', 'upi_account', 'unionpay_account']);

        // Amount & currency
        $amount = $get($data, ['amount', 'transaction_amount']);
        if ($amount === null) {
            $amount = 0.0;
        } else {
            $amount = (float) $amount;
        }

        $currencyRaw = $get($data, ['currency', 'transaction_currency']);
        $currencyCode = null;
        if ($currencyRaw !== null) {
            if (is_numeric($currencyRaw)) {
                $currencyCode = (int) $currencyRaw;
            } else {
                switch (strtoupper((string) $currencyRaw)) {
                    case 'USD':
                        $currencyCode = KHQRData::CURRENCY_USD; break;
                    case 'KHR':
                    default:
                        $currencyCode = KHQRData::CURRENCY_KHR; break;
                }
            }
        } else {
            $currencyCode = KHQRData::CURRENCY_KHR; // default
        }

        // Additional / language data (snake_case or camelCase)
        $billNumber      = $get($data, ['billNumber', 'bill_number']);
        $storeLabel      = $get($data, ['storeLabel', 'store_label']);
        $terminalLabel   = $get($data, ['terminalLabel', 'terminal_label']);
        $mobileNumber    = $get($data, ['mobileNumber', 'mobile_number']);
        $purpose         = $get($data, ['purposeOfTransaction', 'purpose', 'purpose_of_transaction']);
        $languagePref    = $get($data, ['languagePreference', 'language_preference']);
        $merchantNameAlt = $get($data, ['merchantNameAlternateLanguage', 'merchant_name_alt']);
        $merchantCityAlt = $get($data, ['merchantCityAlternateLanguage', 'merchant_city_alt']);

        // Optional override: embed storeLabel into merchantName in the generated payload so
        // scanners/apps that read the merchant name will see the store label instead.
        // Enable by passing one of these truthy keys in $data:
        //  - 'embedStoreLabelAsMerchant'
        //  - 'store_label_as_merchant'
        //  - 'embed_store_label_as_merchant'
        // This is opt-in and does not change display-only label logic unless you also pass
        // displayName when generating images.
        $embedStoreLabelAsMerchant = $get($data, ['embedStoreLabelAsMerchant', 'store_label_as_merchant', 'embed_store_label_as_merchant']);
        if ($embedStoreLabelAsMerchant && !empty($storeLabel)) {
            // overwrite merchantName so payload will contain storeLabel as merchant name
            $merchantName = (string)$storeLabel;
        }

        // For KHR amounts must be integer (library throws otherwise). Auto-normalize by rounding.
        if ($currencyCode === KHQRData::CURRENCY_KHR && is_float($amount) && floor($amount) != $amount) {
            $amount = (float) round($amount); // keep as float for constructor; generation logic will convert
        }

        // Decide individual vs merchant
        $isMerchant = !Utils::isBlank($merchantID) && !Utils::isBlank($acquiringBank);

        if (Utils::isBlank($bakongAccountID) || Utils::isBlank($merchantName) || Utils::isBlank($merchantCity)) {
            throw new \InvalidArgumentException('Missing required KHQR fields: bakongAccountID / merchantName / merchantCity');
        }

        if ($isMerchant) {
            $info = new MerchantInfo(
                $bakongAccountID,
                $merchantName,
                $merchantCity,
                (string) $merchantID,
                (string) $acquiringBank,
                $accountInformation,
                $currencyCode,
                (float) $amount,
                $billNumber,
                $storeLabel,
                $terminalLabel,
                $mobileNumber,
                $purpose,
                $languagePref,
                $merchantNameAlt,
                $merchantCityAlt,
                $upiAccount
            );
            return self::generateKHQR($info, KHQRData::MERCHANT_TYPE_MERCHANT);
        }

        $info = new IndividualInfo(
            $bakongAccountID,
            $merchantName,
            $merchantCity,
            $acquiringBank, // acquiringBank optional
            $accountInformation,
            $currencyCode,
            (float) $amount,
            $billNumber,
            $storeLabel,
            $terminalLabel,
            $mobileNumber,
            $purpose,
            $languagePref,
            $merchantNameAlt,
            $merchantCityAlt,
            $upiAccount
        );
        return self::generateKHQR($info, KHQRData::MERCHANT_TYPE_INDIVIDUAL);
    }

    /** --------------------------- UTILITIES --------------------------------- */

    protected function resolveHeaderLogo(): string
    {
        foreach (['header_logo.png', 'logo.png'] as $file) {
            $path = $this->assetsPath . $file;
            if ($path !== '' && file_exists($path)) {
                return $path;
            }
        }
        return '';
    }

    /** Set or change the TrueType font path at runtime. */
    public function setFontPath(?string $fontPath): void
    {
        $this->fontPath = $fontPath ? $this->normalizePath($fontPath) : null;
    }

    /** Return the active font path (or null if none). */
    public function getFontPath(): ?string
    {
        return $this->fontPath;
    }

    /** Check if TTF rendering is possible (font path present & imagettftext available). */
    public function isTtfAvailable(): bool
    {
        return ($this->fontPath && is_readable($this->fontPath) && function_exists('imagettftext'));
    }

    /** Try to find a suitable .ttf in assets path (prefers KantumruyPro*). */
    private function discoverFont(): ?string
    {
        if ($this->assetsPath === '') {
            return null;
        }
        $prefer = $this->assetsPath . 'KantumruyPro-Medium.ttf';
        if (is_readable($prefer)) {
            return $prefer;
        }
        // Fallback: first .ttf in assets
        $ttfs = @glob($this->assetsPath . '*.ttf') ?: [];
        foreach ($ttfs as $ttf) {
            if (is_readable($ttf)) {
                return $this->normalizePath($ttf);
            }
        }
        return null;
    }

    private function normalizePath(string $path): string
    {
        // Normalize Windows/Unix separators and remove trailing spaces
        $path = rtrim($path);
        return str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    }

    /**
     * Static helper for easy QR image generation from IndividualInfo.
     * Usage: BakongKHQR::createQrImage($individualInfo, $assetsPath, $width, $embedStoreLabelAsMerchant)
     *
     * @param IndividualInfo $individualInfo
     * @param string $assetsPath
     * @param int $width
     * @param bool $embedStoreLabelAsMerchant If true, set payload merchant name to store label so scanners show store label
     */
    /**
     * Static helper for easy QR image generation from IndividualInfo.
     * Usage: BakongKHQR::createQrImage($individualInfo, $assetsPath, $width)
     *
     * If the optional fourth parameter is omitted (null) the helper will auto-detect
     * whether `storeLabel` is present on the provided `IndividualInfo`. When a
     * storeLabel exists the helper will embed the store label into the generated
     * payload's merchant name (so scanners show the store label) and will also
     * display the store label on the image. This is non-breaking behaviour and
     * can be overridden by passing true/false explicitly.
     *
     * @param IndividualInfo $individualInfo
     * @param string $assetsPath
     * @param int $width
     * @param bool|null $embedStoreLabelAsMerchant If null (default) auto-detect from $individualInfo
     * @return string PNG binary
     */
    public static function createQrImage(IndividualInfo $individualInfo, $assetsPath = '', $width = 400, ?bool $embedStoreLabelAsMerchant = null): string
    {
        $gen = self::forLocalGeneration($assetsPath ?: __DIR__ . '/../assets');

        // Determine embedding behavior: if caller passed null, auto-detect based on storeLabel presence.
        $shouldEmbed = $embedStoreLabelAsMerchant === null ? (!empty($individualInfo->storeLabel)) : $embedStoreLabelAsMerchant;

        // Work on a clone to avoid mutating the caller's object
        $infoCopy = clone $individualInfo;
        if ($shouldEmbed && !empty($infoCopy->storeLabel)) {
            $infoCopy->merchantName = $infoCopy->storeLabel;
        }

        $response = self::generateIndividual($infoCopy);
        // Pass string currency code for correct display
        $currencyString = ($infoCopy->currency === KHQRData::CURRENCY_USD) ? 'USD' : 'KHR';

        // Choose display name: prefer storeLabel if present, otherwise merchantName
        $displayName = !empty($infoCopy->storeLabel) ? $infoCopy->storeLabel : $infoCopy->merchantName;

        return $gen->getQrImage([
            'response' => $response,
            'data' => [
                'merchant_name' => $infoCopy->merchantName,
                'amount' => $infoCopy->amount,
                'currency' => $currencyString,
                'store_label' => $infoCopy->storeLabel ?? null,
            ],
            'displayName' => $displayName,
            'currency' => $currencyString,
            'width' => $width
        ]);
    }
}