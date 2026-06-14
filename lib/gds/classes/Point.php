<?php defined('SYSPATH') or die('No direct script access.');


/**
 * Пункт в подсистеме GDS
 *
 * @author V.Skorykh
 * @since 02.02.2016 11:55
 */
class Gate_Gds_Point
{
	/**
	 * @var int ID пункта. Этот ID может совпадать с ID населенного пункта или с ID станции.
	 */
	public $id;

	/**
	 * @var string Название населенного пункта
	 */
	public $name;

	/**
	 * @var string Название региона
	 */
	public $region;

	/**
	 * @var string Уточнение местоположения. Это может быть информация о районе, или же информация о том, какому
	 * автовокзалу принадлежит автостанция
	 */
	public $details;

	/**
	 * @var string Адрес пункта
	 */
	public $address;

	/**
	 * @var string Координаты GPS: широта
	 */
	public $latitude;

	/**
	 * @var string Координаты GPS: долгота
	 */
	public $longitude;

	/**
	 * @var string Код ОКАТО
	 *
	 * @since 1.10
	 */
	public $okato;

	/**
	 * @var bool Признак принадлежности населенного пункта к классу Place
	 *
	 * @since 1.10.4
	 */
	public $place;
}