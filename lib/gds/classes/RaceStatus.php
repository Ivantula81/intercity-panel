<?php defined('SYSPATH') or die('No direct script access.');


/**
 * Статус рейса в подсистеме GDS
 *
 * @author V.Skorykh
 * @since 02.02.2016 12:36
 */
class Gate_Gds_RaceStatus
{
	/**
	 * Неопределенный тип. Например для случая, когда система автовокзала не позволяет уточнить тип рейса.
	 */
	const STATUS_UNKNOWN = 0;

	/**
	 * Рейс в продаже
	 */
	const STATUS_ON_SALE = 1;

	public $id;
	public $name;
}