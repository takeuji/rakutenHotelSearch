<?php

namespace Hotel;

use Noodlehaus\Config;
use Noodlehaus\Parser\Yaml;

class RakutenSearch
{
    const RAKUTEN_DOMAIN = 'https://app.rakuten.co.jp/services/api/Travel/';
    private $conf;

    public function loadConfig(string $configPath)
    {
        $this->conf = Config::load($configPath, new Yaml);
    }

    public function getHotel(string $hotelNo): HotelInfo
    {
        if (!is_numeric($hotelNo)) {
            return new HotelInfo('---', '---');
        }
        $requestURL = RakutenSearch::RAKUTEN_DOMAIN
            . 'HotelDetailSearch/20170426?applicationId=' . $this->conf['application_id']
            . '&format=json'
            . '&hotelNo=' . $hotelNo;
        $html = file_get_contents($requestURL);
        $json = json_decode($html);
        try {
            $hotelNo = $json->hotels[0]->hotel[0]->hotelBasicInfo->hotelNo;
            $hotelName = $json->hotels[0]->hotel[0]->hotelBasicInfo->hotelName;
            return new HotelInfo($hotelNo, $hotelName);
        } catch (\Exception $ex) {
        }
        return new HotelInfo('---', '---');
    }

    public function getBestPricePlan(HotelInfo $hotel, \DateTime $checkinDate, int $adultNum = 2, \DateTime $checkoutDate = null): ?StayPlanInfo
    {
        if (!is_numeric($hotel->getHotelNo())) {
            return null;
        }
        if ($checkoutDate == null) {
            $checkoutDate = clone $checkinDate;
            $checkoutDate->modify('+1 day');
        }
        $requestURL = RakutenSearch::RAKUTEN_DOMAIN
            . 'VacantHotelSearch/20170426?applicationId=' . $this->conf['application_id']
            . '&format=json'
            . '&checkinDate=' . $checkinDate->format('Y-m-d')
            . '&checkoutDate=' . $checkoutDate->format('Y-m-d')
            . '&hotelNo=' . $hotel->getHotelNo()
            . '&adultNum=' . $adultNum;

        $cheapestPlans = null;
        try {
            $html = file_get_contents($requestURL);
            $json = json_decode($html);

            $plans = [];
            foreach ($json->hotels[0]->hotel as $hotelPlans) {
                foreach ($hotelPlans as $roomInfo) {
                    if (property_exists($roomInfo, 'hotelNo')) {
                        continue;
                    }
                    $plan = new StayPlanInfo();
                    $plan->setPlanName($roomInfo[0]->roomBasicInfo->planName)
                        ->setRoomName($roomInfo[0]->roomBasicInfo->roomName)
                        ->setTotalCharge($roomInfo[1]->dailyCharge->total);
                    $plans[] = $plan;
                }
            }

            // ショートステイのプランやバースデイプランなどは除く
            // ショートステイのプランしかない場合は、その中で最安値のプランを検索する
            $normalPlans = array_filter($plans, function (StayPlanInfo $plan) use($adultNum) {
                return !$plan->isShortStay()
                        && !$plan->isDouble()
                        && strpos($plan->getPlanName(), 'バースデ') !== false
                        && strpos($plan->getPlanName(), 'シニア') !== false
                        && ($adultNum == 2 && !$plan->isSemiDouble());
            });
            if ($normalPlans != null && 0 < count($normalPlans)) {
                $plans = $normalPlans;
            }

            $cheapestPlans = null;
            foreach ($plans as $plan) {
                $cheapestPlans = StayPlanInfo::compare($cheapestPlans, $plan);
            }
        } catch (\Exception $ex) {
        }

        return $cheapestPlans;
    }
}

class HotelInfo
{
    private string $hotelNo;
    private string $hotelName;

    public function __construct(string $hotelNo = '', string $hotelName = '')
    {
        $this->setHotelNo($hotelNo);
        $this->setHotelName($hotelName);
    }

    /**
     * @return string
     */
    public function getHotelNo(): string
    {
        return $this->hotelNo;
    }

    /**
     * @param string $hotelNo
     */
    public function setHotelNo(string $hotelNo): void
    {
        $this->hotelNo = $hotelNo;
    }

    /**
     * @return string
     */
    public function getHotelName(): string
    {
        return $this->hotelName;
    }

    /**
     * @param string $hotelName
     */
    public function setHotelName(string $hotelName): void
    {
        $this->hotelName = $hotelName;
    }
}

class StayPlanInfo
{
    private ?string $planName;
    private ?string $roomName;
    private int $totalCharge;

    public static function compare(?StayPlanInfo $plan1, ?StayPlanInfo $plan2, int $adultNum = 1): ?StayPlanInfo
    {
        if ($plan1 == null && $plan2 != null) {
            return $plan2;
        } else if ($plan2 == null && $plan1 != null) {
            return $plan1;
        } else if ($plan1 == null && $plan2 == null) {
            return null;
        }
        if ($plan1->getTotalCharge() < $plan2->getTotalCharge()) {
            return $plan1;
        }
        if ($plan2->getTotalCharge() < $plan1->getTotalCharge()) {
            return $plan2;
        }

        // 価格が同額の場合
        if (strpos($plan1->getRoomName(), '禁煙') !== false
            && strpos($plan2->getRoomName(), '禁煙') === false) {
            return $plan1;
        }
        if (strpos($plan2->getRoomName(), '禁煙') !== false
            && strpos($plan1->getRoomName(), '禁煙') === false) {
            return $plan2;
        }

        if (!$plan1->isShortStay() && $plan2->isShortStay()) {
            return $plan1;
        }
        if (!$plan2->isShortStay() && $plan1->isShortStay()) {
            return $plan2;
        }

        if ($adultNum == 2) {
            // 一方がツインで一方がダブルとどちらも明確にわかる場合には、ツインを優先する
            if (strpos($plan1->getRoomName(), 'ツイン') !== false
                && strpos($plan2->getRoomName(), 'ダブル') !== false) {
                return $plan1;
            }
            if (strpos($plan2->getRoomName(), 'ツイン') !== false
                && strpos($plan1->getRoomName(), 'ダブル') !== false) {
                return $plan2;
            }
        }
        return $plan1;
    }

    public function isShortStay(): bool
    {
        return strpos($this->getPlanName(), 'ショート') !== false;
    }

    public function isSemiDouble(): bool
    {
        return strpos($this->getRoomName(), 'セミダブル') !== false
            || strpos($this->getPlanName(), 'セミダブル') !== false;
    }

    public function isDouble(): bool
    {
        return strpos($this->getRoomName(), 'ダブル') !== false
            || strpos($this->getPlanName(), 'ダブル') !== false;
    }

    /**
     * @return string|null
     */
    public function getRoomName(): ?string
    {
        return $this->roomName;
    }

    /**
     * @param string|null $roomName
     * @return $this
     */
    public function setRoomName(?string $roomName): StayPlanInfo
    {
        $this->roomName = $roomName;
        return $this;
    }

    /**
     * @return int
     */
    public function getTotalCharge(): int
    {
        return $this->totalCharge;
    }

    /**
     * @param int $totalCharge
     * @return $this
     */
    public function setTotalCharge(int $totalCharge): StayPlanInfo
    {
        $this->totalCharge = $totalCharge;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getPlanName(): ?string
    {
        return $this->planName;
    }

    /**
     * @param string|null $planName
     * @return $this
     */
    public function setPlanName(?string $planName): StayPlanInfo
    {
        $this->planName = $planName;
        return $this;
    }

}