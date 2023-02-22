<?php

ini_set('error_reporting', E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

/*
Orders - это контролер данных с рынка для получения преимущества при оценке рыночных процессов. 
*/
class Controller_Orders extends Controller
{
	public $controller = 'orders';
 	const DIVIDER  = "&#9473;&#9473;&#9473;&#9473;&#9473;&#9473;&#9473;&#9473;&#9473;&#9473;&#9473;&#9473;&#9473;&#9473;&#9473;&#9473;\n";
/*
Оценка производится с помощью функции, 
которая выбирает из базы данные по текущему состоянию тикера из списка (цена, изменение цены, объем), сортирует данные и отображает полученный результат
*/
    public function action_check_tracker_growth() {

		// считаем начало текущей пятиминутки рынка
        $from_time = Model_Workhours::get_from_time();
        $now = time();
        $stop_script = false;

		//авторелоад страницы 3 раза подряд, потом тормозиться
         if (!isset($_SESSION['tracker_top_reload']) ) {
            $_SESSION['tracker_top_reload'] = 0;
            $this->context->reload = 1;
        }

        if ( $_SESSION['tracker_top_reload'] < 3 ) {
            $_SESSION['tracker_top_reload'] = $_SESSION['tracker_top_reload'] + 1;
            $this->context->reload = 1;
        } else {
            $_SESSION['tracker_top_reload'] = 0;
            $this->context->reload = 0;

        }

		//считаем количество минут от начала торгов
        $market_opened = Model_Workhours::is_market_opened();
        $workhours = Model_Workhours::get();
        $open_time = $workhours['nyse']['open'];
        $close_time = $workhours['nyse']['close'];

        if($market_opened) {
            $diff = $now - $open_time;
            $diff_min = intval($diff / 60);
            $market = " (opened)";
        } else {
            $diff = $close_time - $open_time;
            $diff_min = intval($diff / 60) - 5;
            $market = " (closed)";
        }

		//показываем на странице кол-во минут от начала торгов и текущую пятиминутку
        $this->context->market_time = $diff_min. "min " . $market;
        $this->context->date =date("Y-m-d H:i", $from_time);

		//список открытых в портфеле тикеров 
        $opened_list = ORM::for_table("ticker_volume_5m")
            ->join('tickers', 'tickers.ticker = ticker_volume_5m.ticker')
            ->select_expr("ticker_volume_5m.*, tickers.eod_id, tickers.price, tickers.today_low")
            ->where_gte('timestamp', $from_time)
            ->where_gt('change', 0)
            ->where('opened', 1)
            ->order_by_asc('timestamp')
            ->find_array('ticker');

        $opened_tickers = array_keys($opened_list);

		//строим топ тикеров по текущему объему
        $growth_tops = Model_Ticker::calc_tracker_growth_tops();

        $fixed_top = $growth_tops['fixed'];
        $text = "<br><br>";
        $text = "Selected tickers:<br>";
        $ticker_size = "150%";
		
		//рисуем топ по индексам
        foreach($fixed_top as $symbol => $item) {

            $ticker_color = "#000";

			//если изменение отрицательное, то цвет однозначно красный
            if($item['change'] < 0) {
                $ticker_color = "#FE2500";
            } else {

				// считаем тренд по пятиминутным барам 
                $color_trend = Model_Ticker::get_bar_color_trend($symbol);

				// если зеленых больше чем красных
                if ($color_trend['up'] > $color_trend['down']) {
                    // то цвет индекса зеленый
					$ticker_color = "#66DA26";
					// и если 6 и более подряд зеленых то увеличиваем шрифт 
                    if($color_trend['up_max'] >= 6) {
                        $ticker_size = "200%";
                    }
                }
				// если красных баров больше, чем зеленых
                if ($color_trend['up'] < $color_trend['down']) {

					// и если 6 и более подряд красных то увеличиваем шрифт и цвет красный 
                    if($color_trend['down_max'] >= 6) {
                        $ticker_size = "200%";
                        $ticker_color = "#FE2500";
                    }
                }

				// если баров поровну
                if ($color_trend['up'] == $color_trend['down']) {

					//и последний зеленый, то цвет индекса зеленый 
                    if($color_trend['last'] == "+") {
                        $ticker_color = "#66DA26";
						// и если 6 и более подряд зеленых то увеличиваем шрифт 
                        if($color_trend['last_period'] >= 6) {
                            $ticker_size = "200%";
                        }
					//и последний красный и 6 и более подряд красных то увеличиваем шрифт и цвет красный
                    } else if($color_trend['last'] == "-") {
                        if($color_trend['last_period'] >= 6) {
                            $ticker_color = "#FE2500";
                            $ticker_size = "200%";
                        }
                    }
                }
            }

			//считаем средний объем индекса в минуту 
            $volume_data = Model_Ticker::calc_volume_by_hour($symbol);
			
			//средний объем за количество минут от начала торгов
            $avg_volume = $volume_data['m'] * $diff_min;
            $volume_marker = "";
            $diff_volume = 0;
            if($avg_volume > 0) {
				
				//берем текущий объем и сравниваем со средним 
                $cur_volume = intval($item['volume'] / 1000000);
                $diff_volume = round($cur_volume / $avg_volume, 1);
				
				//размер шрифта в топе в зависимости от отношения объемов
                if ($diff_volume < 2) {
                    $size = '80%';
                } else if ($diff_volume < 5) {
                    $size = '120%';
                } else {
                    $size = '150%';
                }
            }
			
			//размер шрифта тикера в топе в зависимости от цвета и отношения объемов
            if(($diff_volume >= 1.5)&&($ticker_color == "#FE2500")) {
                $ticker_size = "200%";
            }

            if(($diff_volume >= 1.3)&&($ticker_color == "#66DA26")) {
                $ticker_size = "200%";
            }

			//формируем строку по индексу со ссылкой на 5-минутный график с finviz.com
            $volume_marker = "&nbsp;&nbsp;&nbsp; V:<span style='color:#333'>$cur_volume</span>"."M / <span style='font-size:$size'>$diff_volume"."х</span>";

            $text = $text . "&nbsp;&nbsp;&nbsp;<a href='https://elite.finviz.com/quote.ashx?t=$symbol&p=i5&tas=0' target='_blank' style='text-decoration: none; '>.</a>&nbsp;<a href='tickers.price_growth_stat?ticker=$symbol' target='_blank' style='text-decoration: none;color: $ticker_color; font-size: $ticker_size;'>$symbol</a>$volume_marker";
            $text = $text . "<br>";

        }

        $text = $text . "<hr>";
        $text = $text . "<br><br>";
        $remove = array();
		
		
		//рисуем топ по тикерам из трекеров
        $tracker_top = $growth_tops['tracker'];

        $text = $text . "Trackers top:<br>";

		//считаем мин и макс изменение в топе
        if($tracker_top) {
            $max_change = max(array_column($tracker_top, 'change_pc'));
            $min_change = min(array_column($tracker_top, 'change_pc'));
        } else {
            $max_change = 0;
            $min_change = 0;
        }
        $ticker_size = "150%";
        $first = true;
        foreach($tracker_top as $symbol => $item) {
            $ticker_color = "#000";
            $trend_marker = "";
			
			//считаем по последним 9 барам есть ли DOWN trend
            $trend_data = Model_Ticker::calc_bar_trend($symbol);
            if(is_array($trend_data)) {
                //$trend_marker = "";
				
				//если есть, то кандидат на удаление из топа
                $remove[$symbol] = $symbol;
            }

			// считаем тренд по пятиминутным барам 
            $color_trend = Model_Ticker::get_bar_color_trend($symbol);

			// если зеленых больше чем красных
            if ($color_trend['up'] > $color_trend['down']) {
				// то цвет тикера зеленый
					$ticker_color = "#66DA26";
				// и если 6 и более подряд зеленых то увеличиваем шрифт 
                if($color_trend['up_max'] >= 6) {
                    $ticker_size = "200%";
                }
            }
			// если красных больше чем зеленых
            if ($color_trend['up'] < $color_trend['down']) {
				// то цвет тикера красный
                $ticker_color = "#FE2500";
				// и если 6 и более подряд красных то увеличиваем шрифт 
                if($color_trend['down_max'] >= 6) {
                    $ticker_size = "200%";
                }
            }

			//если баров поровну
            if ($color_trend['up'] == $color_trend['down']) {
				
                if($color_trend['last'] == "+") {
					//и последний зеленый, то цвет тикера зеленый 
                    $ticker_color = "#66DA26";
                } else if($color_trend['last'] == "-") {
					//и последний красный, то цвет тикера красный 
                    $ticker_color = "#FE2500";
                }

				// если 6 и более подряд баров одинаковые, то увеличиваем шрифт
                if($color_trend['last_period'] >= 6) {
                    $ticker_size = "200%";
                }
            }

			
            if($item['change_pc'] == $max_change) {
				// если тикер с максимальным ростом, то увеличиваем шрифт
                $ticker_size = '200%';
            } else if ($item['change_pc'] == $min_change) {
				// если тикер с минимальным ростом, то уменьшаем шрифт
                $ticker_size = '100%';
            } else {
                $ticker_size = '150%';
            }
			
			//считаем средний объем тикера в минуту 
            $volume_data = Model_Ticker::calc_volume_by_hour($symbol);
            $avg_volume = $volume_data['m'] * $diff_min;

            $volume_marker = "";
			
			// начинаем показывать объемы с 15 минуты торгов и вне рынка
            if(($diff_min >= 15)||(!$market_opened)) {
                //берем текущий объем и сравниваем со средним 
				$cur_volume = intval($item['volume'] / 1000000);
				if($cur_volume > $avg_volume) {
                    $diff_volume = round(pc($cur_volume, $avg_volume) / 100, 1) + 1;
					//размер шрифта в топе в зависимости от отношения объемов
                    if($diff_volume < 2) {
                        $size = '80%';
                    } else if ($diff_volume < 5) {
                        $size = '120%';
                    } else {
                        $size = '150%';
                    }
                    $volume_marker = "&nbsp;&nbsp;&nbsp; V:<span style='color:#333'>$cur_volume</span>"."M / <span style='font-size:$size'>$diff_volume"."х</span>";
                } else {
                    $volume_marker = "";
                }
            } else {
                $volume_marker = "";
            }

            $name = $symbol;
			// если тикер первый в топе, показываем рост в %
            if($first){
                $change = intval($item['change_pc']);
                $name =  $name . " " . $change . "%";
                $first = false;
            } else {
                $name =  $name;
            }
			//формируем строку по тикеру со ссылкой на 5-минутный график с finviz.com
            $text = $text . "&nbsp;$trend_marker&nbsp;&nbsp;<a href='https://elite.finviz.com/quote.ashx?t=$symbol&p=i5&tas=0' target='_blank' style='text-decoration: none;'>.</a>&nbsp;<a href='tickers.price_growth_stat?ticker=$symbol' target='_blank' style='text-decoration: none;font-size:$ticker_size;color:$ticker_color;'>$name</a>$volume_marker";
            if($color_trend['bullrun'] == 1) {
                $strategy_nextrun = "132034946";
                $text = $text . "&nbsp;&nbsp;&nbsp;<a  href='orders.buy_lot?ticker=$symbol&cnt=42&strategy=$strategy_nextrun' target='_blank'>Купить 42</a>";
            }
            $text = $text . "<br>";
        }

        $text = $text . "<hr>";
        $text = $text . "<br><br>";
        $global_top = array_keys($tracker_top);
        $opened_top = $growth_tops["opened"];

		//рисуем топ по открытым тикерам из портфеля
        $text = $text . "Opened top:<br>";
        $ticker_size = "150%";
        $first = true;
        foreach($opened_top as $symbol => $item) {
            $trend_marker = "";
            $ticker_color = "#000";
			//считаем по последним 9 барам есть ли DOWN trend
            $trend_data = Model_Ticker::calc_bar_trend($symbol);
            if(is_array($trend_data)) {
                //$trend_marker = $trend_data;
				//если есть, то кандидат на удаление из топа
                $remove[$symbol] = $symbol;
            }
			// считаем тренд по пятиминутным барам 
            $color_trend = Model_Ticker::get_bar_color_trend($symbol);

			// если зеленых больше чем красных
            if ($color_trend['up'] > $color_trend['down']) {
				//то цвет тикера зеленый
                $ticker_color = "#66DA26";
				// и если 6 и более подряд зеленых то увеличиваем шрифт 
                if($color_trend['up_max'] >= 6) {
                    $ticker_size = "200%";
                }
            }
			// если красных больше чем зеленых
            if ($color_trend['up'] < $color_trend['down']) {
				//то цвет тикера красный
                $ticker_color = "#FE2500";
				// и если 6 и более подряд красных то увеличиваем шрифт
                if($color_trend['down_max'] >= 6) {
                    $ticker_size = "200%";
                }
            }
			// если баров поровну
            if ($color_trend['up'] == $color_trend['down']) {
                if($color_trend['last'] == "+") {
					//и последний зеленый, то цвет тикера зеленый 
                    $ticker_color = "#66DA26";
                } else if($color_trend['last'] == "-") {
					//и последний красный, то цвет тикера красный 
                    $ticker_color = "#FE2500";
                }
				
				// если 6 и более подряд баров одинаковые, то увеличиваем шрифт
                if($color_trend['last_period'] >= 6) {
                    $ticker_size = "200%";
                }
            }

			//считаем средний объем тикера в минуту 
            $volume_data = Model_Ticker::calc_volume_by_hour($symbol);
            $avg_volume = $volume_data['m'] * $diff_min;
            $volume_marker = "";
			
			// начинаем показывать объемы с 15 минуты торгов и вне рынка
            if(($diff_min >= 15)||(!$market_opened)) {
				
				//берем текущий объем и сравниваем со средним 
                $cur_volume = intval($item['volume'] / 1000000);
                if($cur_volume > $avg_volume) {
                    $diff_volume = round(pc($cur_volume, $avg_volume) / 100, 1) + 1;
					//размер шрифта в топе в зависимости от отношения объемов
                    if($diff_volume < 2) {
                        $size = '80%';
                    } else if ($diff_volume < 5) {
                        $size = '120%';
                    } else {
                        $size = '150%';
                    }
                    $volume_marker = "&nbsp;&nbsp;&nbsp; V:<span style='color:#333'>$cur_volume</span>"."M / <span style='font-size:$size'>$diff_volume"."х</span>";
                } else {
                    $volume_marker = "";
                }
            } else {
                $volume_marker = "";
            }

            $name = $symbol;
			// если тикер первый в топе, показываем рост в %
            if($first){
                $change = intval($item['change_pc']);
                $name =  $name . " " . $change . "%";
                $first = false;
            } else {
                $name =  $name;
            }

			//формируем строку по тикеру со ссылкой на 5-минутный график с finviz.com
            if(in_array($symbol, $global_top)) {
				//если тикер есть в топе и по трекерам и по открытым тикерам, то выделяется жирным шрифтом
                $text = $text . "&nbsp;$trend_marker&nbsp;&nbsp;<a href='https://elite.finviz.com/quote.ashx?t=$symbol&p=i5&tas=0' target='_blank' style='text-decoration: none;'>.</a>&nbsp;<a href='tickers.price_growth_stat?ticker=$symbol' target='_blank' style='text-decoration: none; font-size: $ticker_size; color: $ticker_color;'><b>$name</b></a>$volume_marker";
            } else {
                $text = $text . "&nbsp;$trend_marker&nbsp;&nbsp;<a href='https://elite.finviz.com/quote.ashx?t=$symbol&p=i5&tas=0' target='_blank' style='text-decoration: none;'>.</a>&nbsp;<a href='tickers.price_growth_stat?ticker=$symbol' target='_blank' style='text-decoration: none; font-size: $ticker_size; color: $ticker_color;'>$name</a>$volume_marker";
            }
            $text = $text . "<br>";
        }

        $text = $text . "<hr>";
        $text = $text . "<br><br>";
		
		// рисуем топ тикеров по текущему росту в %
        $change_top = $growth_tops['change_items'];
        $text = $text . "Сhange top:<br>";

        $first = true;
        $ticker_size = "150%";

        foreach($change_top as $symbol => $item) {
            $trend_marker = "";
			
            //считаем по последним 9 барам есть ли DOWN trend
            $trend_data = Model_Ticker::calc_bar_trend($symbol);
            if(is_array($trend_data)) {
                //$trend_marker = "";
				
				//если есть, то кандидат на удаление из топа
                $remove[$symbol] = $symbol;
            }
			
			//считаем средний объем тикера в минуту 
            $volume_data = Model_Ticker::calc_volume_by_hour($symbol);

            $avg_volume = $volume_data['m'] * $diff_min;

            if($avg_volume > 0) {
				//берем текущий объем и сравниваем со средним 
                $cur_volume = intval($item['volume'] / 1000000);
                $diff_volume = round($cur_volume / $avg_volume, 1);
				
				//размер шрифта в топе в зависимости от отношения объемов
                if ($diff_volume < 2) {
                    $size = '80%';
                } else if ($diff_volume < 5) {
                    $size = '120%';
                } else {
                    $size = '150%';
                }
                $volume_marker = "&nbsp;&nbsp;&nbsp; V:<span style='color:#333'>$cur_volume</span>"."M / <span style='font-size:$size'>$diff_volume"."х</span>";
            } else {
                $volume_marker = "";
            }

			//если тикер есть в топе по открытым тикерам, то выделяется жирным шрифтом
            if(in_array($symbol, $opened_tickers)) {
                $name = "<b>".$symbol."</b>";
            } else {
                $name = $symbol;
            }

			// если тикер первый в топе, показываем рост в %
            if($first){
                $change = intval($item['change']);
                $name =  $name . " " . $change . "%";
                $first = false;
            } else {
                $name =  $name;
            }
            $text = $text . "&nbsp;$trend_marker&nbsp;&nbsp;<a href='https://elite.finviz.com/quote.ashx?t=$symbol&p=i5&tas=0' target='_blank' style='text-decoration: none;'>.</a>&nbsp;<a href='tickers.price_growth_stat?ticker=$symbol' target='_blank' style='text-decoration: none; font-size: $ticker_size;'>$name</a>$volume_marker";
            $text = $text . "<br>";
        }

        $text = $text . "<hr>";
        $text = $text . "<br><br>";


        $html_title = "0-0-0-0-0-0";
        $this->context->html_title = $html_title;
        $this->context->top = $text;
        $this->context->remove = "Remove from top: " . implode(", ", $remove);

        $this->display( $this->controller.'/tracker_top.html' );
        //print_r($text);
        //print_r("Remove from top: " . implode(", ", $remove));
    }
}
