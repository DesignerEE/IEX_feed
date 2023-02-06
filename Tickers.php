<?php

class Controller_Cron_Tickers extends Controller {


	public function action_index() {


    }

	public function action_iex_update() {

        $symbol = _get('ticker', '');

		$time = time();
        $date = new DateTime("now", new DateTimeZone('America/New_York') );
        $timeHi = $date->format('H:i');

        if($symbol == "") {
            if(!Model_Workhours::is_market_opened()) {
                exit;
            }

            if ($timeHi == "09:33") {
                $this->action_update_open_highlow();
            }

            Model_Notifications::send_to_telegram("IEX PRICE UPDATE $timeHi", "-1001305510555");
        }

        if($symbol) {
				$list = ORM::for_table('tickers')
						->where("tickers.ticker", $symbol)
						->find_array('ticker');
		} else {
			$config = ORM::for_table('user_config')
						->where('user_id', 1)
						->find_array('key', 'value');

			$batch = (isset($config['batch']))? json_decode($config['batch']) : $this->tickers_batch;

			$batch_list = ORM::for_table('tickers')
							->where_in("ticker", $batch)
							->find_array('ticker');

 			$list = ORM::for_table('portfolios_tickers')
						->select_expr("tickers.*")
						->where_in("portfolio_id", [1,2,609,610,613])
						->join('tickers', ['tickers.id', '=', 'portfolios_tickers.ticker_id'] )
						->find_array('ticker');

			$list = array_merge($list, $batch_list);

            $sp500 = ORM::for_table('tickers')
                        ->where("tickers.sp500", 1)
						->find_array('ticker');

			$list = array_merge($list, $sp500);

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
		}

		foreach($list as $item) {
			$id = $item['id'];

			$params = [
				'ticker' => $item['ticker'],
				'field' => 'price'
			];
			$new_price = Model_Iexcloud::getField($params);

            if($symbol) {
                print_r($item['ticker']);
                print_r(" " . $new_price . "<br>");
            }

			if (floatval($new_price) > 0 ) {

				if ($timeHi == "09:30") {

					$new_low = $new_price;
				} else {
					if ($new_price < $item['today_low']) {
						$new_low = $new_price;
					} else {
						$new_low = $item['today_low'];
					}
				}

				$sql = "UPDATE tickers SET price = '$new_price', today_low = '$new_low' WHERE id = $id";
				ORM::q($sql);

				$diff = $new_price - $new_low;
				$sql = "INSERT IGNORE INTO ticker_history_5m SET ticker_id = $id, ts = $time, price = $new_price, day_low = $new_low, diff = $diff";
				ORM::q($sql);

				$ret = Model_Ticker::check_pivot_trigger($item['ticker']);
				$ret = Model_Ticker::check_today_growth_trigger($item['ticker']);
			} else {
                Model_Notifications::send_to_telegram("IEX PRICE UPDATE.\n" . $item['ticker'] . " " . $new_price, "-1001305510555");
			}
		}

        if($symbol == "") {
            if(!Model_Workhours::is_market_opened()) {
                exit;
            }
        }

        $this->action_update_volume_5m();

        print_r("DONE");
	}
	
	public function action_update_volume_5m() {

        if(!Model_Workhours::is_market_opened()) {
            exit;
        }

        Model_Notifications::send_to_telegram("IEX VOLUME UPDATE ", "-1001305510555");

        $opened_list =  Model_Collective::getTradesOpened();
        $strategy_unicorn = '******';
        $opened_tickers = array_column($opened_list[$strategy_unicorn], 'ticker');

        $tracker_list = ORM::for_table('portfolios_tickers')
            ->join('tickers', 'tickers.id = portfolios_tickers.ticker_id')
            ->where_in('portfolio_id', [609, 610, 613])
            ->find_array('ticker');

        $opened_list = ORM::for_table('tickers')
            ->where_in('tickers.ticker', $opened_tickers)
            ->find_array('ticker');

        $fixed_list = ORM::for_table('tickers')
            ->where_in('tickers.ticker', array('SPY', 'QQQ'))
            ->find_array('ticker');

        $total_list = array_merge($fixed_list, $tracker_list, $opened_list);

        $tracker_tickers = array_keys($tracker_list);
        $opened_tickers = array_keys($opened_list);

        foreach($total_list as $symbol => $data) {

            $params = [
                'ticker' => $symbol,
                'field' => 'volume'
            ];
            $volume = Model_Iexcloud::getQuoteField($params);

            $params = [
                'ticker' => $symbol,
                'field' => 'change'
            ];
            $change = Model_Iexcloud::getQuoteField($params);

            if(in_array($symbol, $opened_tickers)) {
                $opened = 1;
            } else {
                $opened = 0;
            }

            if(in_array($symbol, $tracker_tickers)) {
                $tracker = 1;
            } else {
                $tracker = 0;
            }

            ORM::q("INSERT INTO ticker_volume_5m (`ticker`, `tracker`, `opened`, `timestamp`, `volume`, `change`) VALUES(:ticker, :tracker, :opened, :time, :volume, :change)", [
                'ticker' => $symbol,
                'tracker' => $tracker,
                'opened' => $opened,
                'time' => time(),
                'volume' => $volume,
                'change' => $change
            ] );
        }
        print_r("DONE");
    }

	public function action_iex_cap_update() {
		$symbol = _get('ticker', '');

		$list = ORM::for_table('tickers')
					->where("sp500", 1)
					->find_array('id', 'ticker');
		if($symbol) {
			$list = ORM::for_table('tickers')
					->where("ticker", $symbol)
					->find_array('id', 'ticker');
		}

		foreach($list as $id => $ticker) {

			$params = [
				'ticker' => $ticker,
				'field' => 'sharesOutstanding'
			];

			$shares = Model_Iexcloud::getStatsField($params);

			if($shares == "Not found") {
				continue;
			}

			$new_shares = round($shares / 1000000000, 4);

			$sql = "UPDATE tickers SET shares_outstanding = '$new_shares' WHERE id = $id";
			ORM::q($sql);

		}

print_r("DONE");
	}

	public function action_iex_divs_update() {
		$symbol = _get('ticker', '');

		$list = ORM::for_table('tickers')
					->where("sp500", 1)
					->find_array('id', 'ticker');
		if($symbol) {
			$list = ORM::for_table('tickers')
					->where("ticker", $symbol)
					->find_array('id', 'ticker');
		}

		foreach($list as $id => $ticker) {

			$params = [
				'ticker' => $ticker,
				'field' => 'dividendYield'
			];

			$divs = Model_Iexcloud::getStatsField($params);

			if($divs == "Not found") {
				continue;
			}

			$new_divs = round($divs * 100, 2);

			$sql = "UPDATE earrings_stat SET dividends_yield = '$new_divs' WHERE ticker_id = $id";
			ORM::q($sql);
		}

print_r("DONE");
	}

    public function action_iex_upcoming_divs_update() {

        $symbol = _get("ticker", "");

        $tickers_list = ORM::for_table('portfolios_tickers')
            ->join('tickers', 'tickers.id = portfolios_tickers.ticker_id');

        if($symbol != "") {
            $tickers_list = $tickers_list->where('tickers.ticker', $symbol);
        } else {
            $tickers_list = $tickers_list->where_in('portfolio_id', [609, 610, 613, 1, 2]);
        }

        $tickers_list = $tickers_list->find_array('ticker');

        foreach($tickers_list as $symbol => $item) {

            $params = [
                'ticker' => $symbol,
                'field' => 'dividends',
                'period' => 'next'
            ];
            $divs_data = Model_Iexcloud::getDivsPeriod($params);

            if($divs_data) {
                $results = array();
                foreach ($divs_data as $row) {

                    $results[] = [
                        'ticker_id' => $item['ticker_id'],
                        'date' => date("Y-m-d", $row['date']/1000),
                        'ex_date' => $row['exDate'],
                        'declared_date' => $row['declaredDate'],
                        'record_date' => $row['recordDate'],
                        'payment_date' => $row['paymentDate'],
                        'type' => $row['description'],
                        'amount' => $row['amount'],
                        'frequency' => $row['frequency']
                    ];
                }

                $sql = SQL::insert_many('tickers_divs', $results, [], ['ignore' => 1]);
                ORM::q($sql);
            }
        }

        print_r("DONE");
    }
	
		public function action_iex_eps_update() {

		$symbol = _get("ticker", "");
		$last = _get("cnt", "10");
		$list = ORM::for_table('tickers');

		if($symbol == "") {
				$list = $list->where('sp500', 1);
		}else{
			$list = $list->where('ticker', $symbol);
		}

		$list = $list->find_array();

		foreach($list as $ticker) {

			$params = [
				'ticker' => $ticker['ticker'],
				'last' => $last,
				'field' => ''
			];
			$eps_data = Model_Iexcloud::getEarnings($params);

			if($eps_data) {
                $results = array();
                foreach ($eps_data['earnings'] as $row) {

                    $period = explode(" ", $row['fiscalPeriod']);

                    $results[] = [
                        'period' => $row['fiscalPeriod'],
                        'report_year' => $period[1],
                        'date' => $row['EPSReportDate'],
                        'market_status' => 1,
                        'consensus_est_eps' => $row['consensusEPS'],
                        'adjusted_actuals_eps' => $row['actualEPS'],
                        'eps_difference' => $row['EPSSurpriseDollar'],
                        'est_lowhigh_range' => '',
                        'gaap_eps' => $row['actualEPS'],
                        'reported_eps' => $row['actualEPS'],
                        'ticker_id' => $ticker['id'],
                        'future' => 0,
                    ];

                    ORM::q("DELETE from fidelity_earrings where ticker_id = " . $ticker['id'] . " and period = '" . $row['fiscalPeriod'] . "'");
                }

                $sql = SQL::insert_many('fidelity_earrings', $results, [], [
                    'on_duplicate_update' => ['consensus_est_eps', 'adjusted_actuals_eps', 'eps_difference', 'gaap_eps', 'reported_eps', 'future', 'date']
                ]);
                ORM::q($sql);
            }

            $upcoming_eps_data = Model_Iexcloud::getUpcomingEarnings($params);

            if($upcoming_eps_data) {
                ORM::q("DELETE from fidelity_earrings where ticker_id = " . $ticker['id'] . " and future = 1");

                $last_earnings = ORM::for_table('fidelity_earrings')
                    ->where("ticker_id" , $ticker['id'])
                    ->where("future" , 0)
                    ->order_by_desc("date")
                    ->find_one();
                if(!$last_earnings) {
                    continue;
                }
                $last_earnings = $last_earnings->as_array();

                $last_year = $last_earnings['report_year'];
                $last_q = substr($last_earnings['period'], 0, 2);

                if ($last_q == "Q4") {
                    $next_year = $last_year + 1;
                    $next_period = "Q1 " . $next_year;
                } else {
                    $q_val = substr($last_q, 1, 1);
                    $next_q = $q_val + 1;
                    $next_year = $last_year;
                    $next_period = "Q" . $next_q . " " . $next_year;
                }

                $results = array();
                $results[] = [
                    'period' => $next_period,
                    'report_year' => $next_year,
                    'date' => $upcoming_eps_data[0]['reportDate'],
                    'market_status' => 0,
                    'consensus_est_eps' => '',
                    'adjusted_actuals_eps' => '',
                    'eps_difference' => '',
                    'est_lowhigh_range' => '',
                    'gaap_eps' => '',
                    'reported_eps' => '',
                    'ticker_id' => $ticker['id'],
                    'future' => 1,
                ];

                $sql = SQL::insert_many('fidelity_earrings', $results, [], []);
                ORM::q($sql);
            }

			//print_r(" DONE<br>");
		}

		$this->json([
			"success" => 1,
			"ticker" => $ticker['ticker'],
			"cnt" => count($results)
		]);
	}

	public function action_iex_target_update($params) {

		$symbol = _get("ticker", array_get($params, 'ticker'));

		if($symbol == "") {
			$list = ORM::for_table('tickers');
			$list = $list->where('sp500', 1);
			$list = $list->find_array();
		}else{
			$list = ORM::for_table('tickers');
			$list = $list->where('ticker', $symbol);
			$list = $list->find_array();
		}

		foreach($list as $item) {
			$ticker = $item['ticker'];
			$ticker_id = $item['id'];

			$cur_target = ORM::for_table('alerts_settings')
							->where('user_id', 1)
							->where('ticker_id', $ticker_id)
							->where('type', 3)
							->find_array();
			if ($cur_target) {
				$target = json_decode($cur_target[0]['value']);
				$target_max = (isset($target[1]) ? $target[1] : 0);
			} else {
				$target = ["", 0];
				$target_max = 0;
			}

			if(($symbol == "")&&($item['price'] > $target_max)) {
				continue;
			}
			$params = [
				'ticker' => $ticker
			];
			$iex_data = Model_Iexcloud::getTargetPrice($params);

			if($iex_data) {
				$currency = $iex_data['currency'];
				if($currency != 'USD') {
					$params = [
						'symbols' => $iex_data['currency'] . "USD",
						'amount' => $iex_data['priceTargetHigh']
					];
					$iex_conversion = Model_Iexcloud::getConversion($params);
					if($iex_conversion) {
						$target_max = $iex_conversion['amount'];
						$target[1] = $target_max;
						$target_value = json_encode($target);

						$sql = "DELETE FROM alerts_settings WHERE type = 3 AND user_id = 1 AND ticker_id = $ticker_id;";
						ORM::q($sql);
						$sql = "INSERT INTO alerts_settings(type, user_id, ticker_id, value) VALUES (3, 1, $ticker_id, '$target_value')";
						ORM::q($sql);

					}
				} else {
					$target_max = $iex_data['priceTargetHigh'];
					$target[1] = $target_max;
					$target_value = json_encode($target);

					$sql = "DELETE FROM alerts_settings WHERE type = 3 AND user_id = 1 AND ticker_id = $ticker_id;";
					ORM::q($sql);
					$sql = "INSERT INTO alerts_settings(type, user_id, ticker_id, value) VALUES (3, 1, $ticker_id, '$target_value') ON DUPLICATE KEY UPDATE value = '$target_value'";
					ORM::q($sql);
				}
			}

		}
print_r("DONE");
	}
	
}
