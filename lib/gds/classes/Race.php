<?php defined('SYSPATH') or die('No direct script access.');


/**
 * Рейс в подсистеме GDS
 *
 * @author V.Skorykh
 * @since 02.02.2016 11:59
 */
class Gate_Gds_Race
{
	public $uid;
	public $depotId;
	public $num;
	public $name;
	public $dispatchDate;
	public $arrivalDate;
	public $dispatchStationName;
	public $arrivalStationName;

	/**
	 * @var int ID пункта отправления (на стороне GDS)
	 *
	 * @since 1.10.7
	 */
	public $dispatchPointId;

	/**
	 * @var int ID пункта прибытия (на стороне GDS)
	 *
	 * @since 1.10.7
	 */
	public $arrivalPointId;

	public $supplierPrice;
	public $price;
	public $freeSeatCount;
	public $freeSeatEstimation;
	public $busInfo;
	public $carrier;

	/**
	 * @var string ИНН организации-перевозчика
	 * @since 1.7.4
	 */
	public $carrierInn;

	/**
	 * @var bool Признак необходимости воодить расширенный набор персонадбныз данных
	 */
	public $dataRequired;

	/**
	 * @var Gate_Gds_RaceType Тип рейса
	 */
	public $type;

	/**
	 * @var Gate_Gds_RaceClass Класс рейса (Регулярный/Заказной)
	 * @since 1.10
	 */
	public $clazz;

	/**
	 * @var Gate_Gds_RaceStatus Cтатус рейса
	 */
	public $status;

	/**
	 * @var bool Признак поступления данных из кэша
	 */
	public $fromCache;

	public function isOnSale()
	{
		return ($this->status->id == Gate_Gds_RaceStatus::STATUS_ON_SALE);
	}

}