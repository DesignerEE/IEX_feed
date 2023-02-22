<?php

class Controller_Cron_Tickers extends Controller {


	public function action_index() {


    }

/*
	Сбор данных с фида "IEX Cloud" - текущая цена по тикеру 
*/
	public function action_iex_update() {


		$time = time();
        $date = new DateTime("now", new DateTimeZone('America/New_York') );
        $timeHi = $date->format('H:i');

		if(!Model_Workhours::is_market_opened()) {
			exit;
		}

		// второй запуск крона в рынке, обновляем дневной минимум цены тикера  
		if ($timeHi == "09:33") {
			$this->action_update_open_highlow();
		}

		$config = ORM::for_table('user_config')
					->where('user_id', 1)
					->find_array('key', 'value');

		$batch = (isset($config['batch']))? json_decode($config['batch']) : $this->tickers_batch;

		// список тикеров из батча 	
		$batch_list = ORM::for_table('tickers')
						->where_in("ticker", $batch)
						->find_array('ticker');

		// список тикеров из трекер-портфелей 	
		$list = ORM::for_table('portfolios_tickers')
					->select_expr("tickers.*")
					->where_in("portfolio_id", [1,2,609,610,613])
					->join('tickers', ['tickers.id', '=', 'portfolios_tickers.ticker_id'] )
					->find_array('ticker');

		$list = array_merge($list, $batch_list);

		// список тикеров из индекса SP500
		$sp500 = ORM::for_table('tickers')
					->where("tickers.sp500", 1)
					->find_array('ticker');

		$list = array_merge($list, $sp500);
		
		// список открытых тикеров в портфелях
		$opened_list =  Model_Collective::getTradesOpened(false, false, true);

		$opened_tickers = array();
		foreach($opened_list as $strategy => $opened) {
			$strategy_tickers = array_column($opened, 'ticker');
			$opened_tickers = array_merge($opened_tickers, $strategy_tickers);
		}

		$opened_list = ORM::for_table('tickers')
			->where_in("ticker", $opened_tickers)
			->find_array('ticker');

		$list = array_merge($list, $opened_list);

		// создаем объект RollingCurl и назначаем колбек для запросов
		$rc = new RollingCurl([$this, 'price_update_response']);

        foreach($list as $item) {

            $params = [
                'ticker' => $item['ticker'],
                'field' => 'price'
            ];
			// формируем урл запроса к фиду и добавляем запрос в очередь
            $request = new RollingCurlRequest(Model_Iexcloud::getFieldURL($params), 'GET', null, null, default_curl_opts(), array('item' => $item, 'timeHi' => $timeHi));
            $rc->add($request);
        }
		// кол-во параллельных потоков 
        $rc->execute(50);

        // запускаем очередь запросов
        $this->action_iex_update_volume_20();

	}
	
	//функция обратного вызова, обработка ответа от каждого запроса
	public function price_update_response($response, $info, $request) {

        $time = time();

        $item = $request->store['item'];
        $timeHi = $request->store['timeHi'];

        $id = $item['id'];
        $symbol = $item['ticker'];

		//новая цена, полученная из запроса к фиду 
        $new_price = $response;
        if (floatval($new_price) > 0 ) {

            if ($timeHi == "09:30") {
				// при первом запросе сбрасываем значения минимальной и максимальной цены внутри дня
                $new_low = $new_price;
                $new_high = $new_price;
            } else {
				// при каждом обновлении цены обновляем значения минимальной и максимальной цены внутри дня
                $new_low = ($new_price < $item['today_low']) ? $new_price : $item['today_low'];
                $new_high = ($new_price > $item['today_high']) ? $new_price : $item['today_high'];
            }

			// пишем новые значения цены, минимума и максимума в базу
            $sql = "UPDATE tickers SET price = '$new_price', today_low = '$new_low', today_low_time = $time, today_high = '$new_high', today_high_time = $time WHERE id = $id";
            ORM::q($sql);

			// проверяем есть ли PIVOT триггер 
            $ret = Model_Ticker::check_pivot_trigger($item['ticker']);

			// проверяем есть ли триггер превышения темпа роста
            $ret = Model_Ticker::check_today_growth_trigger($item['ticker']);
        }else{
			
			// если из фида пришла не цена, то пишем это в лог канал
            Model_Notifications::send_to_telegram("IEX PRICE UPDATE.\n" . $item['ticker'] . " " . $new_price, "-1001305510555");
        }
    }
	
	
/*
	Сбор данных с фида "IEX Cloud" - текущее изменение цены и текущий объем по тикеру 
*/
	public function action_update_volume_5m() {

        if(!Model_Workhours::is_market_opened()) {
            exit;
        }

		// список открытых тикеров в портфеле 	
        $opened_list =  Model_Collective::getTradesOpened();
        $strategy_unicorn = '******';
        $opened_tickers = array_column($opened_list[$strategy_unicorn], 'ticker');

		// выбираем тикеры из трекер портфелей 
        $tracker_list = ORM::for_table('portfolios_tickers')
            ->join('tickers', 'tickers.id = portfolios_tickers.ticker_id')
            ->where_in('portfolio_id', [609, 610, 613])
            ->find_array('ticker');

		// выбираем тикеры, которые открыты в портфеле
        $opened_list = ORM::for_table('tickers')
            ->where_in('tickers.ticker', $opened_tickers)
            ->find_array('ticker');

		// выбираем индексы
        $fixed_list = ORM::for_table('tickers')
            ->where_in('tickers.ticker', array('SPY', 'QQQ'))
            ->find_array('ticker');

		// общий список тикеров без повторов
        $total_list = array_merge($fixed_list, $tracker_list, $opened_list);

        $tracker_tickers = array_keys($tracker_list);
        $opened_tickers = array_keys($opened_list);

        foreach($total_list as $symbol => $data) {

			// получаем поле volume из фида - текущий объем
            $params = [
                'ticker' => $symbol,
                'field' => 'volume'
            ];
			$volume = Model_Iexcloud::getQuoteField($params);

			// получаем поле change из фида - текущее изменение цены
            $params = [
                'ticker' => $symbol,
                'field' => 'change'
            ];
            $change = Model_Iexcloud::getQuoteField($params);

			// отмечаем тикер, если он в списке открытых
            if(in_array($symbol, $opened_tickers)) {
                $opened = 1;
            } else {
                $opened = 0;
            }

			// отмечаем тикер, если он в списке трекеров
            if(in_array($symbol, $tracker_tickers)) {
                $tracker = 1;
            } else {
                $tracker = 0;
            }

			// пишем в таблицу новую запись 
            ORM::q("INSERT INTO ticker_volume_5m (`ticker`, `tracker`, `opened`, `timestamp`, `volume`, `change`) VALUES(:ticker, :tracker, :opened, :time, :volume, :change)", [
                'ticker' => $symbol,
                'tracker' => $tracker,
                'opened' => $opened,
                'time' => time(),
                'volume' => $volume,
                'change' => $change
            ] );
        }
    }
}

