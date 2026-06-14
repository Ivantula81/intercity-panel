<?php

function println($value) {
	echo $value;
	echo "\n";
}

function find_station_by_name($stations, $station_name) {
	foreach ($stations as $station) {
		if ($station->name == $station_name) {
			return $station;
		}
	}
	return FALSE;
}

function find_by_name($items, $name) {
	foreach ($items as $item) {
		if ($item->name == $name) {
			return $item;
		}
	}
	return NULL;
}

function find_by_code($items, $name) {
	foreach ($items as $item) {
		if ($item->code == $name) {
			return $item;
		}
	}
	return NULL;
}

function get_date($offset_in_days = 0)
{
	return date('d.m.Y', time() + 86400 * $offset_in_days);
}

function print_stations($stations) {
	foreach ($stations as $station) {
		println($station->name . ' (' . $station->id . ')');
	}
}

class System
{
	public static function is_debug_mode()
	{
		return TRUE;
	}
}

class Gate_Exception  extends Exception
{


}

class Strings
{
	/**
	 * Проверка на вхождение одной строки в другую. Поиск производится с учетом регистра.
	 *
	 * @param string $haystack Строка, в которой производится поиск
	 * @param string $needle Образец для поиска
	 * @param bool $ignore_case Флаг необходимости игнорировтаь регистр букв
	 * @return bool true, если строка найдена
	 */
	public static function contains($haystack, $needle, $ignore_case = FALSE) {
		if ($ignore_case) {
			return $needle === "" || (stripos($haystack, $needle) !== FALSE);
		} else {
			return $needle === "" || (strpos($haystack, $needle) !== FALSE);
		}
	}

	/**
	 * Проверка на совпадание начала строки с заданным образцом. Поиск производится с учетом регистра.
	 *
	 * @param string $haystack Строка, в которой производится поиск
	 * @param string $needle Образец для поиска
	 * @return bool true, если строка найдена
	 */
	public static function starts_with($haystack, $needle) {
		// search backwards starting from haystack length characters from the end
		return $needle === "" || strrpos($haystack, $needle, -strlen($haystack)) !== FALSE;
	}

	/**
	 * Проверка на совпадание конца строки с заданным образцом. Поиск производится с учетом регистра.
	 *
	 * @param string $haystack Строка, в которой производится поиск
	 * @param string $needle Образец для поиска
	 * @return bool true, если строка найдена
	 */
	public static function ends_with($haystack, $needle) {
		// search forward starting from end minus needle length characters
		return $needle === "" || (($temp = strlen($haystack) - strlen($needle)) >= 0 && strpos($haystack, $needle, $temp) !== FALSE);
	}

	/**
	 * Преобразование строки из кодировки Windows-1251 в кодировку UTF-8
	 *
	 * @param string $value Исходная строка в кодировке Windows-1251
	 * @return string Строка в кодировке UTF-8
	 */
	public static function to_utf8($value) {
		return iconv('CP1251', 'UTF-8', $value);
	}

	/**
	 * Преобразование строки из кодировки UTF-8 в кодировку Windows-1251
	 *
	 * @param string $value Исходная строка в кодировке UTF-8
	 * @return string Строка в кодировке Windows-1251
	 */
	public static function to_win1251($value) {
		return iconv('UTF-8', 'CP1251', $value);
	}

    /*
    * @param string $string Первый символ строки в апперкейсе остальные в ловеркейсе на utf8
    * @return string
    */
    public static function capitalize($string){
        $string = mb_strtoupper(mb_substr($string, 0, 1,'UTF-8'),'UTF-8') . mb_strtolower(mb_substr($string, 1,mb_strlen($string)-1,'UTF-8'),'UTF-8');
        return $string;
    }

    /*
* @param string $string Первый символ каждого слова в строке в апперкейсе остальные в ловеркейсе на utf8
* @return string
*/
    public static function capitalizeWords($string){
        $result = [];
        $words = explode(' ',$string);
        foreach($words as $str){
            $result[] = self::capitalize($str);
        }
        return implode(' ',$result);
    }

}


// Заглушка логгера
class Logger {

	public static function error($message, $exception = NULL) {
		if (($exception != NULL) && ($exception instanceof Exception)) {
			$msg = $message . "\n" .
				'Error code: ' . $exception->getCode() . "\n" .
				'Error message: ' . $exception->getMessage() . "\n" .
				"Error trace: \n" .
				$exception->getTraceAsString() . "\n";
			println($msg);
		} else {
			println($message);
		}
	}

	public static function info($message, $object = NULL) {
		if (TRUE) {
			if (is_string($message)) {
				if ($object == NULL) {
					println($message);
				} else {
					println($message . ' ' . var_export($object, TRUE));
				}
			} else {
				println(var_export($message, TRUE));
			}
		}
	}

	public static function debug($message, $object = NULL) {
		if (TRUE) {
			if (is_string($message)) {
				if ($object == NULL) {
					println($message);
				} else {
					println($message . ' ' . var_export($object, TRUE));
				}
			} else {
				println(var_export($message, TRUE));
			}
		}
	}

}

class Kohana {

	public static function cache($cache_key, $cache_value, $lifetime) {
		return NULL;
	}

}