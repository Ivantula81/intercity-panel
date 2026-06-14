<?php defined('SYSPATH') or die('No direct script access.');


/**
 * Информация о забронированном/проданном билете в подсистеме GDS
 *
 * @author V.Skorykh
 * @since 02.02.2016 13:01
 */
class Gate_Gds_Ticket
{
	/**
	 * Статус "Билет забронирован"
	 */
	const STATUS_BOOKED = 'B';

	/**
	 * Статус "Билет продан"
	 */
	const STATUS_SOLD = 'S';

	/**
	 * Статус "Билет возвращен"
	 */
	const STATUS_RETURNED = 'R';

	/**
	 * Статус "Билет испорчен (отменен)"
	 */
	const STATUS_CANCELLED = 'C';

	/**
	 * Класс билета: пассажирский
	 */
	const CLASS_PASSENGER = 'P';

	/**
	 * Класс билета: багажный
	 */
	const CLASS_BAGGAGE = 'B';

	public $id;
	public $ticketCode;
	public $ticketNum;
	public $ticketSeries;
	public $ticketClass;
	public $ticketType;

	/**
	 * @var string UID рейса
	 * @since 1.10
	 */
	public $raceUid;

	public $raceNum;
	public $raceName;

	/**
	 * @var int ID класса рейса
	 */
	public $raceClassId;

	public $dispatchDate;
	public $dispatchStation;
	public $dispatchAddress;

	public $arrivalDate;
	public $arrivalStation;
	public $arrivalAddress;

	public $seat;
	public $platform;

	public $lastName;
	public $firstName;
	public $middleName;

	public $docType;
	public $docSeries;
	public $docNum;

	public $citizenship;
	public $gender;
	public $birthday;

	/**
	 * @var string Контактный телефон пассажира
	 * @since 1.11.0
	 */
	public $phone;

	public $supplierFare;
	public $supplierDues;
	public $supplierPrice;
	public $supplierRepayment;
	public $dues;
	public $price;
	public $repayment;

	public $busInfo;
	public $carrier;
	public $carrierInn;

	/**
	 * @var string Штрих-код билета
	 * @since 1.11.0
	 */
	public $barcode;

	/**
	 * @var boolean Флаг возможности изменить данные в билете
	 *
	 * @since 1.10
	 */
	public $updatable;

	public $status;
	public $created;
	public $returned;

	public $benefit;

	/**
	 * @var string Хэш-код билета. Используется для загрузки файла билета, сгенерированного на стороне GDS.
	 * @since 1.11.0
	 */
	public $hash;
}
