<?php

/**
 * Ticker model
 *
 * @package Finance
 * @author  Alexey Vasilkov
 */
 
 
/*
 Ticker - класс, содержащий набор функций для обработки данных по тикеру
*/ 
class Model_Ticker
{

	const DIVIDER  = "&#9473;&#9473;&#9473;&#9473;&#9473;&#9473;&#9473;&#9473;&#9473;&#9473;&#9473;&#9473;&#9473;&#9473;&#9473;&#9473;\n";

	// обратная сортировка по полю
    static function cmp_desc_diff($a, $b)
    {
        $al = (float)$a['diff'];
        $bl = (float)$b['diff'];
        if ($al == $bl) {
            return 0;
        }
        return ($al > $bl) ? -1 : +1;
    }
	// прямая сортировка по полю
    static function cmp_asc_diff($a, $b)
    {
        $al = (float)$a['diff'];
        $bl = (float)$b['diff'];
        if ($al == $bl) {
            return 0;
        }
        return ($al > $bl) ? +1 : -1;
    }


/*
Таблица `ticker_volume_5m` заполняется функцией action_iex_update_volume_20 в файле Cron_Tickers.php 
Запуск функции раз в 3 минуты по крону
*/

	//строим топ тикеров по текущему объему
    public static function calc_tracker_growth_tops() {
        echo memory_get_usage();
        $result_tops = array(
            'fixed' => array(),
            'tracker' => array(),
            'opened' => array(),
            'change' => array()
        );

		// считаем начало текущей пятиминутки рынка
        $from_time = Model_Workhours::get_from_time();
		
		//выбираем данные за последние 5 минут по тикерам из трекеров
        $tracker_list = ORM::for_table("ticker_volume_5m")
            ->join('tickers', 'tickers.ticker = ticker_volume_5m.ticker')
            ->select_expr("ticker_volume_5m.*, tickers.eod_id, tickers.price, tickers.today_low")
            ->where_gte('timestamp', $from_time)
            ->where_gt('change', 0)
            ->where('tracker', 1)
            ->order_by_asc('timestamp')
            ->find_array('ticker');

		//выбираем данные за последние 5 минут по открытым тикерам из портфеля
        $opened_list = ORM::for_table("ticker_volume_5m")
            ->join('tickers', 'tickers.ticker = ticker_volume_5m.ticker')
            ->select_expr("ticker_volume_5m.*, tickers.eod_id, tickers.price, tickers.today_low")
            ->where_gte('timestamp', $from_time)
            ->where_gt('change', 0)
            ->where('opened', 1)
            ->order_by_asc('timestamp')
            ->find_array('ticker');

		//выбираем данные за последние 5 минут по индексам
        $fixed_list = ORM::for_table("ticker_volume_5m")
            ->join('tickers', 'tickers.ticker = ticker_volume_5m.ticker')
            ->select_expr("ticker_volume_5m.*, tickers.eod_id, tickers.price, tickers.today_low")
            ->where_gte('timestamp', $from_time)
            ->where_in('ticker_volume_5m.ticker', array('SPY', 'QQQ'))
            ->order_by_asc('timestamp')
            ->find_array('ticker');

        $fixed_result = array();

        $opened_tickers = array_keys($opened_list);

        foreach($fixed_list as $symbol => $item) {
            //$symbol = $item['ticker'];

			// считаем нормальный интервал отклонения цены
            $year_stat_interval = Model_Ticker::get_year_stat_interval($item['eod_id']);
            if($year_stat_interval) {
				// считаем текущее изменение цены в %
                $end_int = $year_stat_interval[1];
                $change = $item['change'];
                $prev_price = $item['price'] - $change;
                $change_pc = pc($item['price'], $prev_price);
                $low_change_pc = 0;
                $item['type'] = "";
				
				// сравниваем текущее изменение с нормальным
                if ($change_pc/$end_int >= 1.5) {
					//экстра превышение темпа роста
                    $item['type'] = "+++";
                } else if ($change_pc >= $end_int) {
					//превышение темпа роста
                    $item['type'] = "+";
				//если рост есть, но не превышает нормальный интервал, то считаем изменение от дневного минимума	
                } else if (($change_pc > 0)&&($item['type'] == "")) {
                    $low_change_pc = pc($item['price'], $item['today_low']);
                    if ($low_change_pc/$end_int >= 1.5) {
						//экстра превышение темпа роста
                        $item['type'] = "+++";
                    } else if ($low_change_pc >= $end_int) {
						//превышение темпа роста
                        $item['type'] = "+";
                    }
                }

                $volume = $item['volume'];
                $item['diff'] = $volume;
                $item['change_pc'] = ($change_pc > $low_change_pc) ? $change_pc : $low_change_pc;
                $fixed_result[$symbol] = $item;
            }
        }

        $result_tops['fixed'] = $fixed_result;

        $tracker_extra_result = array();
        $tracker_growth_result = array();
        $change_list = array();
        foreach($tracker_list as $symbol => $item) {

			// считаем нормальный интервал отклонения цены
            $year_stat_interval = Model_Ticker::get_year_stat_interval($item['eod_id']);
            if($year_stat_interval) {
				
				// считаем текущее изменение цены в %
                $end_int = $year_stat_interval[1];
                $change = $item['change'];
                $prev_price = $item['price'] - $change;
                $change_pc = pc($item['price'], $prev_price);
                $item['change_pc'] = $change_pc;

                $item['type'] = "";
                $volume = $item['volume'];
				// сравниваем текущее изменение с нормальным
                if ($change_pc/$end_int >= 1.5) {
					//экстра превышение темпа роста
                    $item['type'] = "+++";
                    $item['diff'] = $volume;
                    $item['change_pc'] = $change_pc;
                    $tracker_extra_result[$symbol] = $item;
                } else if ($change_pc >= $end_int) {
					//превышение темпа роста
                    $item['type'] = "+";
                    $item['diff'] = $volume;
                    $item['change_pc'] = $change_pc;
                    $tracker_growth_result[$symbol] = $item;
				//если рост есть, но не превышает нормальный интервал, то считаем изменение от дневного минимума	
                } else if (($change_pc > 0)&&($item['type'] == "")) {
                    $low_change_pc = pc($item['price'], $item['today_low']);
                    if ($low_change_pc/$end_int >= 1.5) {
						//экстра превышение темпа роста
                        $item['type'] = "+++";
                        $item['diff'] = $volume;
                        $item['change_pc'] = $low_change_pc;
                        $tracker_extra_result[$symbol] = $item;
                    } else if ($low_change_pc >= $end_int) {
						//превышение темпа роста
                        $item['type'] = "+";
                        $item['diff'] = $volume;
                        $item['change_pc'] = $low_change_pc;
                        $tracker_growth_result[$symbol] = $item;
                    }
                }

				// поле для сортировки меняем на рост в % и добавляем тикер в общий список тикеров с ростом
                $item['diff'] = $item['change_pc'];
                $change_list[$symbol] = $item;
            }
        }

		//сортируем топы по текущему объему
        uasort($tracker_extra_result, array('self', 'cmp_desc_diff'));
        uasort($tracker_growth_result, array('self', 'cmp_desc_diff'));

		// берем первые 5 тикеров 
        $tracker_extra_top = array_slice($tracker_extra_result, 0, 5);
        $tracker_growth_top = array_slice($tracker_growth_result, 0, 5);

		// если тикеров с экстра ростом нет, то в результат даем топ тикеров с простым превышением темпов
        $result_tops['tracker'] = (count($tracker_extra_top)> 0) ? $tracker_extra_top : $tracker_growth_top;


		//тикеры которые попали в топ, удаляем из общего списка 
        $tracker_list = array_keys($result_tops['tracker']);
        foreach($change_list as $symbol => $item) {
            if(in_array($symbol, $tracker_list)) {
                unset($change_list[$symbol]);
            }
        }

        $opened_extra_result = array();
        $opened_growth_result = array();

        foreach($opened_list as $symbol => $item) {
			// считаем нормальный интервал отклонения цены
            $year_stat_interval = Model_Ticker::get_year_stat_interval($item['eod_id']);

            if($year_stat_interval) {
				// считаем текущее изменение цены в %
                $end_int = $year_stat_interval[1];
                $change = $item['change'];
                $prev_price = $item['price'] - $change;
                $change_pc = pc($item['price'], $prev_price);
                $volume = $item['volume'];
                $item['type'] = "";
                $item['change_pc'] = $change_pc;
				
				// сравниваем текущее изменение с нормальным
                if ($change_pc/$end_int >= 1.5) {
					//экстра превышение темпа роста
                    $item['type'] = "+++";
                    $item['diff'] = $volume;
                    $item['change_pc'] = $change_pc;
                    $opened_extra_result[$symbol] = $item;
                } else if ($change_pc >= $end_int) {
					//превышение темпа роста
                    $item['type'] = "+";
                    $item['diff'] = $volume;
                    $item['change_pc'] = $change_pc;
                    $opened_growth_result[$symbol] = $item;
					//если рост есть, но не превышает нормальный интервал, то считаем изменение от дневного минимума	
                } else if (($change_pc > 0)&&($item['type'] == "")) {
                    $low_change_pc = pc($item['price'], $item['today_low']);
                    if ($low_change_pc/$end_int >= 1.5) {
						//экстра превышение темпа роста
                        $item['type'] = "+++";
                        $item['diff'] = $volume;
                        $item['change_pc'] = $low_change_pc;
                        $opened_extra_result[$symbol] = $item;
                    } else if ($low_change_pc >= $end_int) {
						//превышение темпа роста
                        $item['type'] = "+";
                        $item['diff'] = $volume;
                        $item['change_pc'] = $low_change_pc;
                        $opened_growth_result[$symbol] = $item;
                    }
                }

				// поле для сортировки меняем на рост в % и добавляем тикер в общий список тикеров с ростом
                $item['diff'] = $item['change_pc'];
                $change_list[$symbol] = $item;
            }
        }

		//сортируем топы по текущему объему
        uasort($opened_extra_result, array('self', 'cmp_desc_diff'));
        uasort($opened_growth_result, array('self', 'cmp_desc_diff'));

		// оставляем первые 5 тикеров
        $opened_extra_top = array_slice($opened_extra_result, 0, 5);
        $opened_growth_top = array_slice($opened_growth_result, 0, 5);

		// если тикеров с экстра ростом нет, то в результат даем топ тикеров с простым превышением темпов
        $result_tops['opened'] = (count($opened_extra_top) > 0) ? $opened_extra_top : $opened_growth_top;

		//тикеры которые попали в топ, удаляем из общего списка 
        $opened_list = array_keys($result_tops['opened']);
        foreach($change_list as $symbol => $item) {
            if(in_array($symbol, $opened_list)) {
                unset($change_list[$symbol]);
            }
        }

		//сортируем список по текущему изменению цены в %
        uasort($change_list, array('self', 'cmp_desc_diff'));
		//оставляем первые 5 тикеров
        $change_top = array_slice($change_list, 0, 5);
        $result_tops['change'] = $change_top;

        return $result_tops;
    }
	
	
	// функция считаем тренд по послеовательности пятиминутных баров
	public static function get_bar_color_trend($symbol) {
        $market_opened = Model_Workhours::is_market_opened();
        $market = Model_Workhours::get();
        if($market_opened) {
            $from_time = $market['nyse']['open'];
        } else {
            $N = date("N");
            if($N == 1) {
                $from_time = $market['nyse']['open'] - 3 * 24 * 3600;
            }else{
                $from_time = $market['nyse']['open'] - 24 * 3600;
            }
        }
		// выбираем все бары за текущий торговый день
        $data = ORM::for_table('ticker_bar_5m')
            ->where('ticker', $symbol)
            ->where_gte('timestamp', $from_time)
            ->order_by_asc('timestamp')
            ->find_array();

        $result = array(
            'up' => 0,
            'up_max' => 0,
            'down' => 0,
            'down_max' => 0,
            'last' => "",
            'last_period' => 0,
            'bullrun' => 0
        );

        $cur_trend = "";
        $period = 0;
		
		
        foreach($data as $key => $item) {
            $diff = $item['close'] - $item['open'];
            
			//цвет текущего бара: зеленый (+), красный(-)   
			if($diff == 0) {
                $trend = "";
            } else {
                $trend = ($diff > 0) ? '+' : '-';
            }
            if ($cur_trend == "") {
                $cur_trend = $trend;
            }

			// если цвет бара совпадает с предыдущим состоянием
            if ($cur_trend == $trend) {
				
				// количество одинаковых по цвету баров подряд
                $period += 1;
                if (($period%6) == 0) {
                    if ($trend == 1) {
                        $result['up'] += 1;
                    } else if ($trend == -1){
                        $result['down'] += 1;
                    }

                }
				
			// если цвет бара НЕ совпадает с предыдущим состоянием	
            } else {
				// если количество одинаковых подряд меньше 4, то сбрасываем тренд (четкого нет)
                if (($period < 4)&&(count($data) > 4)) {
                    $cur_trend = "";
                    $period = 0;
                } else {
					
					// если последовательность зеленых баров, то фиксируем UP последовательность
                    if ($cur_trend == '+') {
                        $result['up'] += 1;
						// если текущий период длиннее, то обновляем данные о максимальной длине тренда
                        if ($period > $result['up_max']) {
                            $result['up_max'] = $period;
                        }
                    }
					// если последовательность красных баров, то фиксируем DOWN последовательность
                    if ($cur_trend == '-') {
                        $result['down'] += 1;
						// если текущий период длиннее, то обновляем данные о максимальной длине тренда
                        if ($period > $result['down_max']) {
                            $result['down_max'] = $period;
                        }
                    }

					//фиксируем информацию о последнем тренде  
                    $result['last'] = $cur_trend;
                    $result['last_period'] = $period;
                    $cur_trend = "";
                    $period = 0;
                }
            }
        }

		//обрабатываем тренд после последнего бара	
        if ($period >= 4) {
			// если последовательность зеленых баров, то фиксируем UP последовательность
            if ($cur_trend == '+') {
                $result['up'] += 1;
				// если текущий период длиннее, то обновляем данные о максимальной длине тренда
                if ($period > $result['up_max']) {
                    $result['up_max'] = $period;
                }
            }
			// если последовательность красных баров, то фиксируем DOWN последовательность
            if ($cur_trend == '-') {
                $result['down'] += 1;
				// если текущий период длиннее, то обновляем данные о максимальной длине тренда
                if ($period > $result['down_max']) {
                    $result['down_max'] = $period;
                }
            }

			//фиксируем информацию о последнем тренде  
            $result['last'] = $cur_trend;
            $result['last_period'] = $period;
        }
		
		// если последний тренд зеленый 6 и более баров подряд, то фиксируем булран
        if(($cur_trend == '+')&&($period >= 6)) {
            $result['bullrun'] = 1;
        }

        return $result;
    }
	
	//считаем средний объем индекса в минуту 
	public static function calc_volume_by_hour($code) {

		//выбираем из базы объем средний по тикеру 
        $avg_volume = ORM::for_table("tickers")
            ->where('ticker', $code)
            ->find_array();
        $res = array();
		
        $res['v'] = $avg_volume[0]['volume']; // средний объем
        $res['m'] = round($avg_volume[0]['volume'] / 390, 2); // средний объем в минуту
        $res['h'] = round($avg_volume[0]['volume'] / 6.5, 2); // средний объем в час

        return $res;
    }
}
