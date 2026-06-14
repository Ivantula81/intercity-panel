<?php defined('SYSPATH') or die('No direct script access.');


/**
 * Информация о продаже в подсистеме GDS
 *
 * @author V.Skorykh
 * @since 02.02.2016 12:58
 */
class Gate_Gds_Sale
{
	const GENDER_MALE = "M";
	const GENDER_FEMALE = "F";

	public $lastName;
	public $firstName;
	public $middleName;

	public $docTypeCode;
	public $docSeries;
	public $docNum;

	public $gender;
	public $citizenship;
	public $birthday;

	/**
	 * @var string Контактный телефон пассажира.
	 * Передача телефона на сервер автовокзала работает только для AVS5 и Авибус. В остальных случаях информация
	 * хранится только в базе данных GDS.
	 *
	 * @since 1.11.0
	 */
	public $phone;

	public $seatCode;
	public $ticketTypeCode;

	public $benefit;
}
