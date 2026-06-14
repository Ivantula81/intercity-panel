<?php defined('SYSPATH') or die('No direct script access.');


/**
 * Остановка на рейсе в подсистеме GDS
 *
 * @author V.Skorykh
 * @since 02.02.2016 12:39
 */
class Gate_Gds_Stop
{
	public $code;
	public $name;
	public $regionName;

	public $dispatchDate;
	public $arrivalDate;
	public $stopTime;
	public $distance;
}