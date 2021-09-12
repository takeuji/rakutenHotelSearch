<?php

use League\Csv\Exception as CSVException;
use League\Csv\Reader;
use League\Csv\Writer;
use Hotel\RoomType;

require_once "vendor/autoload.php";

$rakuten = new Hotel\RakutenSearch();
$rakuten->loadConfig('config.yaml');
$io = new IOManager();

$hotelNos = $io->getHotelsFromHotelNoCSV('hotel.csv');
$searchDate = $io->getSearchDateFromDateCSV('date.csv');

$csvData = [];

$roomType = [[RoomType::NON],[RoomType::DOUBLE, RoomType::SEMI_DOUBLE],[RoomType::TWIN]];
$avoidWord = ['シニア','バースデ', '誕生日', 'レイト', 'レディース', '18時', '19時'];
foreach ($hotelNos as $no) {
    $hotel = $rakuten->getHotel($no);
    $time = 0;

    $csvRow = [];
    $csvRow[] = $hotel->getHotelName();
    foreach ($searchDate as $date) {
        $plan = $rakuten->getBestPricePlan($hotel, $date, $avoidWord, 1, $roomType[0]);
        $price = isset($plan) ? $plan->getTotalCharge() : 0;
        $csvRow[] = $price;
        if ($time % 2 == 0) sleep(1);
        $time++;
    }
    $csvData[] = $csvRow;

    $csvRow = [];
    $csvRow[] = $hotel->getHotelName();
    foreach ($searchDate as $date) {
        $plan = $rakuten->getBestPricePlan($hotel, $date, $avoidWord, 2, $roomType[1]);
        $price = isset($plan) ? $plan->getTotalCharge() : 0;
        $csvRow[] = $price;
        if ($time % 2 == 0) sleep(1);
        $time++;
    }
    $csvData[] = $csvRow;

    $csvRow = [];
    $csvRow[] = $hotel->getHotelName();
    foreach ($searchDate as $date) {
        $plan = $rakuten->getBestPricePlan($hotel, $date, $avoidWord, 2, $roomType[2]);
        $price = isset($plan) ? $plan->getTotalCharge() : 0;
        $csvRow[] = $price;
        if ($time % 2 == 0) sleep(1);
        $time++;
    }
    $csvData[] = $csvRow;
}

$file = new SplTempFileObject();
$writer = Writer::createFromFileObject($file);
$writer->insertAll($csvData);

$fileName = 'price.csv';
$csvStr = $writer->getContent();
file_put_contents($fileName, $csvStr);

class IOManager
{
    public function getHotelsFromHotelNoCSV(string $filePath): array
    {
        $hotelNos = [];
        try {
            $reader = Reader::createFromPath($filePath, 'r');

            $records = $reader->getRecords();
            foreach ($records as $idx => $row) {
                if ($idx === 0) {
                    continue;
                }
                $hotelNos[] = $row[0];
            }
        } catch (CSVException $e) {
        }

        return $hotelNos;
    }

    public function getSearchDateFromDateCSV(string $filePath): array
    {
        $date = [];
        try {
            $reader = Reader::createFromPath($filePath, 'r');

            $records = $reader->getRecords();
            foreach ($records as $idx => $row) {
                try {
                    $date[] = new DateTime($row[0]);
                } catch (\Exception $e) {
                }
            }
        } catch (CSVException $e) {
        }

        return $date;
    }
}
