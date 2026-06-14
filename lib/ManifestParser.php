<?php

class ManifestParser
{
    public function parseFile($path, $originalName)
    {
        $contents = file_get_contents($path);
        if ($contents === false || $contents === '') {
            throw new RuntimeException('Файл пустой или недоступен для чтения.');
        }

        $utf8 = $this->toUtf8($contents);
        $rows = $this->parseCsv($utf8);
        if (count($rows) < 2) {
            throw new RuntimeException('В CSV не найдены строки ведомости.');
        }

        $headers = array_shift($rows);
        $headers = array_map(array($this, 'cleanValue'), $headers);
        $records = array();

        foreach ($rows as $row) {
            if ($this->isEmptyRow($row)) {
                continue;
            }

            $row = array_pad($row, count($headers), '');
            $records[] = array_combine($headers, array_slice($row, 0, count($headers)));
        }

        if (empty($records)) {
            throw new RuntimeException('В ведомости нет строк данных.');
        }

        $trip = $this->extractTrip($records);
        $passengers = $this->extractPassengers($records);
        $warnings = $this->buildWarnings($trip, $passengers);
        $routeParts = $this->splitRoute($trip['route']);
        $uniquePhones = array();

        foreach ($passengers as $passenger) {
            if ($passenger['phone_valid']) {
                $uniquePhones[$passenger['phone']] = true;
            }
        }

        return array(
            'file_name' => basename((string) $originalName),
            'trip' => $trip,
            'passengers' => $passengers,
            'unique_phones' => array_keys($uniquePhones),
            'warnings' => $warnings,
            'gds' => array(
                'date' => $this->dateOnly($trip['departure_at']),
                'from' => $routeParts[0],
                'to' => $routeParts[1],
            ),
        );
    }

    private function toUtf8($contents)
    {
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents);
        if (preg_match('//u', $contents)) {
            return $contents;
        }

        $converted = @iconv('Windows-1251', 'UTF-8//IGNORE', $contents);
        if ($converted === false || $converted === '') {
            throw new RuntimeException('Не удалось определить кодировку CSV.');
        }

        return $converted;
    }

    private function parseCsv($contents)
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $contents);
        rewind($stream);

        $firstLine = fgets($stream);
        rewind($stream);
        $delimiter = substr_count($firstLine, ';') >= substr_count($firstLine, ',') ? ';' : ',';
        $rows = array();

        while (($row = fgetcsv($stream, 0, $delimiter, '"', '\\')) !== false) {
            $rows[] = array_map(array($this, 'cleanValue'), $row);
        }

        fclose($stream);
        return $rows;
    }

    private function extractTrip($records)
    {
        $record = $records[0];

        return array(
            'id' => $this->first($record, array('ID', 'ID1')),
            'route' => $this->first($record, array('Маршрут', 'Рейс')),
            'departure_at' => $this->first($record, array('Дата/время отправления', 'date1')),
            'carrier' => $this->first($record, array('ATP')),
            'bus' => $this->first($record, array('Номер_авт.', 'Транспорт', 'Transport')),
            'drivers' => $this->first($record, array('Водители')),
        );
    }

    private function extractPassengers($records)
    {
        $passengers = array();

        foreach ($records as $record) {
            $phone = $this->normalizePhone($this->first($record, array('PHONE', 'Телефон')));
            $lastName = $this->first($record, array('LNAME'));
            $firstName = $this->first($record, array('FNAME'));
            $middleName = $this->first($record, array('MNAME'));
            $name = trim($lastName . ' ' . $firstName . ' ' . $middleName);

            if ($name === '') {
                $name = $this->first($record, array('Пассажир'));
                $name = preg_replace('/\s+(Паспорт|Свидетельство|Загранпаспорт).*$/ui', '', $name);
            }

            $name = trim((string) $name);
            $nameWithoutMarkers = preg_replace('/[СН]:/u', '', $name);
            $nameWithoutMarkers = preg_replace('/\s+/u', '', $nameWithoutMarkers);
            if ($nameWithoutMarkers === '') {
                $name = '';
            }

            if ($phone === '' && $name === '') {
                continue;
            }

            $birthdate = $this->first($record, array('BIRTHDATE'));
            if (strpos($birthdate, '1899') !== false) {
                $birthdate = '';
            }

            $fromId = $this->first($record, array('id станции отправления'));
            $toId = $this->first($record, array('id станции прибытия'));

            // стоимость
            $priceRaw = $this->first($record, array('Стоимость', 'Тариф'));
            $price = preg_replace('/[^\d.]/', '', str_replace(',', '.', $priceRaw));
            // гражданство
            $citizenship = $this->first($record, array('STATE'));
            // свободный комментарий кассира (BIRTHPLACE_NAME) — там любая заметка: оплата, пожелание и т.п.
            $payNote = $this->first($record, array('BIRTHPLACE_NAME'));

            $passengers[] = array(
                'seat' => $this->first($record, array('Место')),
                'name' => $name,
                'phone' => $phone,
                'phone_valid' => (bool) preg_match('/^\+\d{10,15}$/', $phone),
                'from' => $this->first($record, array('Ст.отправления')),
                'to' => $this->first($record, array('Ст.назначения')),
                'status' => $this->first($record, array('Статус', 'STATE')),
                'doc' => trim($this->first($record, array('TYPE_DOC')) . ' '
                    . $this->first($record, array('DOC_SERIES')) . ' '
                    . $this->first($record, array('DOC_NUMBER'))),
                'birthdate' => $birthdate,
                'ticket' => trim($this->first($record, array('Серия_билета')) . ' '
                    . $this->first($record, array('Номер_билета'))),
                'from_id' => ctype_digit($fromId) ? (int) $fromId : null,
                'to_id' => ctype_digit($toId) ? (int) $toId : null,
                'price' => $price !== '' ? (float) $price : null,
                'citizenship' => $citizenship,
                'pay_note' => trim($payNote),
            );
        }

        return $passengers;
    }

    private function buildWarnings($trip, $passengers)
    {
        $warnings = array();

        if ($trip['route'] === '') {
            $warnings[] = 'Не удалось определить маршрут.';
        }
        if ($trip['departure_at'] === '' || strpos($trip['departure_at'], '1899') !== false) {
            $warnings[] = 'Не удалось определить корректную дату отправления.';
        }
        if (empty($passengers)) {
            $warnings[] = 'Не найдено ни одного пассажира с именем или телефоном.';
        }

        $invalid = 0;
        foreach ($passengers as $passenger) {
            if (!$passenger['phone_valid']) {
                $invalid++;
            }
        }
        if ($invalid > 0) {
            $warnings[] = 'Некорректных или пустых телефонов: ' . $invalid . '.';
        }

        return $warnings;
    }

    private function splitRoute($route)
    {
        $parts = preg_split('/\s+[—–-]\s+/u', trim((string) $route), 2);
        return array(isset($parts[0]) ? trim($parts[0]) : '', isset($parts[1]) ? trim($parts[1]) : '');
    }

    private function dateOnly($value)
    {
        if (preg_match('/(\d{2})\.(\d{2})\.(\d{4})/', (string) $value, $matches)) {
            if ((int) $matches[3] < 2000) {
                return '';
            }
            return $matches[3] . '-' . $matches[2] . '-' . $matches[1];
        }
        return '';
    }

    private function normalizePhone($phone)
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);
        if (strlen($digits) === 11 && $digits[0] === '8') {
            $digits = '7' . substr($digits, 1);
        } elseif (strlen($digits) === 10) {
            $digits = '7' . $digits;
        }

        return strlen($digits) === 11 ? '+' . $digits : trim((string) $phone);
    }

    private function first($record, $names)
    {
        foreach ($names as $name) {
            if (isset($record[$name]) && trim((string) $record[$name]) !== '') {
                return trim((string) $record[$name]);
            }
        }
        return '';
    }

    private function isEmptyRow($row)
    {
        foreach ($row as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }
        return true;
    }

    private function cleanValue($value)
    {
        return trim(str_replace("\xC2\xA0", ' ', (string) $value));
    }
}
