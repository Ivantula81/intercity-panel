<?php defined('SYSPATH') or die('No direct script access.');


/**
 * Информация об автовокзале в подсистеме GDS
 *
 * @author V.Skorykh
 * @since 02.02.2016 12:15
 */
class Gate_Gds_Depot
{
	public $id;
	public $name;
	public $address;
	public $timezone;

	public $engine;
	public $version;
	public $features;
	public $ticketLimit;
	public $bookingTimeLimit;
	public $online;
}