<?php defined('SYSPATH') or die('No direct script access.');


/**
 * Тип рейса в подсистеме GDS
 *
 * @author V.Skorykh
 * @since 30.03.2016 13:19
 */
class Gate_Gds_RaceType
{
	/**
	 * Неопределенный тип. Например для случая, когда система автовокзала не позволяет уточнить тип рейса.
	 */
	const TYPE_UNKNOWN = 0;

	public $id;
	public $name;
}