<?php

class GdsLookup
{
    private $server;

    public function __construct()
    {
        require_once __DIR__ . '/gds/incGds.php';
        global $server;
        if (!is_object($server)) {
            throw new RuntimeException('Не удалось инициализировать соединение с GDS.');
        }
        $this->server = $server;
    }

    public function search($date, $from, $to)
    {
        $date = trim((string) $date);
        $from = trim((string) $from);
        $to = trim((string) $to);

        if (!$this->validDate($date)) {
            throw new InvalidArgumentException('Укажите дату рейса в формате ГГГГ-ММ-ДД.');
        }
        if ($from === '' || $to === '') {
            throw new InvalidArgumentException('Укажите станции отправления и прибытия.');
        }

        $dispatchPoints = $this->asArray($this->server->get_dispatch_points(0));
        $dispatchMatches = $this->rankPoints($dispatchPoints, $from);
        if (empty($dispatchMatches)) {
            throw new RuntimeException('GDS не нашёл пункт отправления «' . $from . '». Попробуйте сократить название до города.');
        }

        $attempts = array();
        $bestEmptyMatch = null;

        foreach (array_slice($dispatchMatches, 0, 4) as $dispatchMatch) {
            $dispatch = $dispatchMatch['point'];
            $arrivalPoints = $this->asArray($this->server->get_arrival_points($dispatch->id, $this->cityPattern($to)));
            $arrivalMatches = $this->rankPoints($arrivalPoints, $to);

            foreach (array_slice($arrivalMatches, 0, 4) as $arrivalMatch) {
                $arrival = $arrivalMatch['point'];
                $attempts[] = $this->pointLabel($dispatch) . ' → ' . $this->pointLabel($arrival);
                $races = $this->asArray($this->server->get_races($dispatch->id, $arrival->id, $date));

                if (!empty($races)) {
                    return array(
                        'query' => array('date' => $date, 'from' => $from, 'to' => $to),
                        'dispatch' => $this->pointData($dispatch),
                        'arrival' => $this->pointData($arrival),
                        'races' => $this->formatRaces($races),
                        'attempts' => $attempts,
                    );
                }

                if ($bestEmptyMatch === null) {
                    $bestEmptyMatch = array($dispatch, $arrival);
                }
            }
        }

        if ($bestEmptyMatch !== null) {
            return array(
                'query' => array('date' => $date, 'from' => $from, 'to' => $to),
                'dispatch' => $this->pointData($bestEmptyMatch[0]),
                'arrival' => $this->pointData($bestEmptyMatch[1]),
                'races' => array(),
                'attempts' => $attempts,
            );
        }

        throw new RuntimeException('GDS не нашёл пункт прибытия «' . $to . '» для выбранного отправления.');
    }

    private function formatRaces($races)
    {
        $items = array();

        foreach ($races as $race) {
            $status = isset($race->status) && is_object($race->status) ? $race->status : null;
            $uid = isset($race->uid) ? (string) $race->uid : '';
            $items[] = array(
                'uid' => $uid,
                'statement_number' => $this->extractStatementNumber($uid),
                'number' => isset($race->num) ? (string) $race->num : '',
                'name' => isset($race->name) ? (string) $race->name : '',
                'dispatch_at' => isset($race->dispatchDate) ? (string) $race->dispatchDate : '',
                'arrival_at' => isset($race->arrivalDate) ? (string) $race->arrivalDate : '',
                'dispatch_station' => isset($race->dispatchStationName) ? (string) $race->dispatchStationName : '',
                'arrival_station' => isset($race->arrivalStationName) ? (string) $race->arrivalStationName : '',
                'carrier' => isset($race->carrier) ? (string) $race->carrier : '',
                'bus' => isset($race->busInfo) ? (string) $race->busInfo : '',
                'free_seats' => isset($race->freeSeatCount) ? (string) $race->freeSeatCount : '',
                'status_id' => $status !== null && isset($status->id) ? (string) $status->id : '',
                'status_name' => $status !== null && isset($status->name) ? (string) $status->name : '',
            );
        }

        usort($items, array($this, 'sortRaces'));
        return $items;
    }

    private function extractStatementNumber($uid)
    {
        $parts = explode(':', (string) $uid);
        if (count($parts) < 5 || !isset($parts[1]) || !ctype_digit($parts[1])) {
            return null;
        }

        return (int) $parts[1];
    }

    private function sortRaces($a, $b)
    {
        return strcmp($a['dispatch_at'], $b['dispatch_at']);
    }

    private function rankPoints($points, $query)
    {
        $ranked = array();
        foreach ($points as $point) {
            if (!is_object($point) || !isset($point->id)) {
                continue;
            }

            $score = $this->pointScore($point, $query);
            if ($score > 0) {
                $ranked[] = array('score' => $score, 'point' => $point);
            }
        }

        usort($ranked, array($this, 'sortPointScores'));
        return $ranked;
    }

    private function pointScore($point, $query)
    {
        $queryNormalized = $this->normalize($query);
        $name = isset($point->name) ? $this->normalize($point->name) : '';
        $details = isset($point->details) ? $this->normalize($point->details) : '';
        $address = isset($point->address) ? $this->normalize($point->address) : '';
        $combined = trim($name . ' ' . $details . ' ' . $address);

        if ($queryNormalized === '' || $name === '') {
            return 0;
        }
        if ($queryNormalized === $name || $queryNormalized === trim($name . ' ' . $details)) {
            return 100;
        }
        if (strpos($combined, $queryNormalized) !== false) {
            return 90;
        }
        if (strpos($queryNormalized, $name) !== false) {
            $score = 70;
            foreach ($this->tokens($queryNormalized) as $token) {
                if (strlen($token) >= 4 && strpos($combined, $token) !== false) {
                    $score += 5;
                }
            }
            return min(89, $score);
        }

        $city = $this->normalize($this->cityPattern($query));
        if ($city !== '' && ($name === $city || strpos($name, $city) === 0 || strpos($city, $name) === 0)) {
            return 55;
        }

        return 0;
    }

    private function sortPointScores($a, $b)
    {
        if ($a['score'] === $b['score']) {
            return strcmp($this->pointLabel($a['point']), $this->pointLabel($b['point']));
        }
        return $a['score'] > $b['score'] ? -1 : 1;
    }

    private function pointData($point)
    {
        return array(
            'id' => isset($point->id) ? (string) $point->id : '',
            'name' => isset($point->name) ? (string) $point->name : '',
            'details' => isset($point->details) ? (string) $point->details : '',
            'address' => isset($point->address) ? (string) $point->address : '',
            'label' => $this->pointLabel($point),
        );
    }

    private function pointLabel($point)
    {
        $parts = array();
        foreach (array('name', 'details', 'address') as $field) {
            if (isset($point->$field) && trim((string) $point->$field) !== '') {
                $parts[] = trim((string) $point->$field);
            }
        }
        return implode(', ', array_unique($parts));
    }

    private function cityPattern($value)
    {
        $value = trim((string) $value);
        $value = preg_replace('/[\(\"«].*$/u', '', $value);
        return trim($value);
    }

    private function normalize($value)
    {
        $value = mb_strtolower((string) $value, 'UTF-8');
        $value = str_replace(array('ё', '«', '»', '"', "'"), array('е', ' ', ' ', ' ', ' '), $value);
        $value = preg_replace('/[^a-zа-я0-9]+/ui', ' ', $value);
        return trim(preg_replace('/\s+/u', ' ', $value));
    }

    private function tokens($value)
    {
        return preg_split('/\s+/u', trim((string) $value));
    }

    private function asArray($value)
    {
        if ($value === null) {
            return array();
        }
        return is_array($value) ? $value : array($value);
    }

    private function validDate($date)
    {
        $parsed = DateTime::createFromFormat('Y-m-d', $date);
        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }
}
