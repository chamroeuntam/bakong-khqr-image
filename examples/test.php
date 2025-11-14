<?php
require_once __DIR__ . '/../vendor/autoload.php';

use KHQR\BakongKHQR;
use KHQR\Models\IndividualInfo;
use KHQR\Helpers\KHQRData;

//Generate KHQR for an individual
$individualInfo = new IndividualInfo(
    bakongAccountID: 'chamroeun_tam@wing',
    merchantName: 'Chamroeun Tam',
    merchantCity: 'PHNOM PENH',    
    storeLabel: 'PCM Coffee',
    currency: KHQRData::CURRENCY_KHR,
    amount: 168
);

$qrImage = BakongKHQR::createQrImage($individualInfo); 
file_put_contents(__DIR__ . '/khqr_khr.png', $qrImage);
echo "QR image saved to khqr_khr.png\n";