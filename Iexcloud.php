<?php

class Model_Iexcloud {

	const PK = "pk_***********";
	const SK = "sk_***********";
	const API_QUOTE_URL = "https://cloud.iexapis.com/stable/stock/{ticker}/quote/{field}?token={token}";
	const API_STATS_URL = "https://cloud.iexapis.com/stable/stock/{ticker}/stats/{field}?token={token}";
	const API_STOCK_URL = "https://cloud.iexapis.com/stable/stock/{ticker}/{field}?token={token}";
	const API_DIVS_URL = "https://cloud.iexapis.com/stable/stock/{ticker}/{field}/{period}?token={token}";
	const API_EARNINGS_URL = "https://cloud.iexapis.com/stable/stock/{ticker}/earnings/{last}/{field}?token={token}";
	const API_ANNUAL_EARNINGS_URL = "https://cloud.iexapis.com/stable/stock/{ticker}/earnings/{last}/?period=annual&token={token}";
    const API_UPCOMING_EARNINGS_URL = "https://cloud.iexapis.com/stable/stock/{ticker}/upcoming-earnings/?token={token}";
    const API_TARGET_URL = "https://cloud.iexapis.com/stable/stock/{ticker}/price-target/?token={token}";
    const API_UPCOMING_DIVS_URL = "https://cloud.iexapis.com/stable/stock/{ticker}/upcoming-dividends/?exactDate={exactDate}&token={token}";
    const API_CONVERSION_URL = "https://cloud.iexapis.com/stable/fx/convert?symbols={symbols}&amount={amount}&token={token}";
    const API_INTRADAY_PRICES_URL = "https://cloud.iexapis.com/stable/stock/{ticker}/intraday-prices?chartIEXOnly=true&chartLast={last}&token={token}";


   public static function getFieldURL($params) {

        $params['ticker'] = str_replace("-", ".", $params['ticker']);
        $url = str_replace(['{ticker}', '{field}', '{token}'], [$params['ticker'], $params['field'], self::SK], self::API_STOCK_URL);

        return $url;

    }

    public static function getField($params) {

        $params['ticker'] = str_replace("-", ".", $params['ticker']);
		$url = str_replace(['{ticker}', '{field}', '{token}'], [$params['ticker'], $params['field'], self::SK], self::API_STOCK_URL);

		$result = remote_request($url);

		$field_data = $result['response'];

		return $field_data;
	}

	public static function getStatsField($params) {

        $params['ticker'] = str_replace("-", ".", $params['ticker']);
		$url = str_replace(['{ticker}', '{field}', '{token}'], [$params['ticker'], $params['field'], self::SK], self::API_STATS_URL);

		$result = remote_request($url);

		$field_data = $result['response'];

		return $field_data;
	}
	
	public static function getQuoteFieldURL($params) {
        $params['ticker'] = str_replace("-", ".", $params['ticker']);
        $url = str_replace(['{ticker}', '{field}', '{token}'], [$params['ticker'], $params['field'], self::SK], self::API_QUOTE_URL);

        return $url;
    }

    public static function getQuoteField($params) {

        $params['ticker'] = str_replace("-", ".", $params['ticker']);
		$url = str_replace(['{ticker}', '{field}', '{token}'], [$params['ticker'], $params['field'], self::SK], self::API_QUOTE_URL);

		$result = remote_request($url);

		$data = $result['response'];

        if($params['field'] == "") {
            return json_decode($data, true);
        } else {
            return $data;
        }

	}

	public static function getEarnings($params) {

        $params['ticker'] = str_replace("-", ".", $params['ticker']);
		$url = str_replace(['{ticker}', '{last}', '{field}', '{token}'], [$params['ticker'], $params['last'], $params['field'], self::SK], self::API_EARNINGS_URL);

		$result = remote_request($url);

		$data = $result['response'];

        if (($data == 'Unknown symbol')||($data == '[]')) {
			return false;
		}
		return json_decode($data, true);
	}

	public static function getAnnualEarnings($params) {

        $params['ticker'] = str_replace("-", ".", $params['ticker']);
		$url = str_replace(['{ticker}', '{last}', '{token}'], [$params['ticker'], $params['last'], self::SK], self::API_ANNUAL_EARNINGS_URL);

		$result = remote_request($url);

		$data = $result['response'];

        if (($data == 'Unknown symbol')||($data == '[]')) {
			return false;
		}
		return json_decode($data, true);
	}

    public static function getUpcomingEarnings($params) {

        $params['ticker'] = str_replace("-", ".", $params['ticker']);
		$url = str_replace(['{ticker}', '{token}'], [$params['ticker'], self::SK], self::API_UPCOMING_EARNINGS_URL);

		$result = remote_request($url);

		$data = $result['response'];

		if (($data == 'Unknown symbol')||($data == '[]')) {
			return false;
		}
		return json_decode($data, true);
	}

	public static function getTargetPrice($params) {

        $params['ticker'] = str_replace("-", ".", $params['ticker']);
		$url = str_replace(['{ticker}', '{token}'], [$params['ticker'], self::SK], self::API_TARGET_URL);

		$result = remote_request($url);

		$data = $result['response'];

        if (($data == 'Unknown symbol')||($data == '[]')) {
			return false;
		}
		return json_decode($data, true);
	}

    public static function getDivsPeriod($params) {

        $params['ticker'] = str_replace("-", ".", $params['ticker']);
        $url = str_replace(['{ticker}', '{field}', '{period}', '{token}'], [$params['ticker'], $params['field'], $params['period'], self::SK], self::API_DIVS_URL);

        $result = remote_request($url);

        $field_data = $result['response'];

        return json_decode($field_data, true);
    }

	public static function getUpcomingDivs($params) {

        $params['ticker'] = str_replace("-", ".", $params['ticker']);
		$url = str_replace(['{ticker}','{exactDate}', '{token}'], [$params['ticker'], $params['exactDate'], self::SK], self::API_UPCOMING_DIVS_URL);

		$result = remote_request($url);

		$data = $result['response'];

        if (($data == 'Unknown symbol')||($data == '[]')) {
			return false;
		}
		return json_decode($data, true);
	}

    public static function getUpcomingDivsPeriod($params) {

        $params['ticker'] = str_replace("-", ".", $params['ticker']);
		$url = str_replace(['{ticker}','{from}','{to}', '{token}'], [$params['ticker'], $params['from'], $params['to'], self::SK], self::API_UPCOMING_DIVS_URL);

		$result = remote_request($url);

		$data = $result['response'];

        if (($data == 'Unknown symbol')||($data == '[]')) {
			return false;
		}
		return json_decode($data, true);
	}


    public static function getIntradayPrices($params) {

        $params['ticker'] = str_replace("-", ".", $params['ticker']);
		$url = str_replace(['{ticker}','{last}', '{token}'], [$params['ticker'], $params['last'], self::SK], self::API_INTRADAY_PRICES_URL);

		$result = remote_request($url);

		$data = $result['response'];

        if (($data == 'Unknown symbol')||($data == '[]')) {
			return false;
		}
		return json_decode($data, true);
	}

	public static function getConversion($params) {

        $params['symbols'] = str_replace("-", ".", $params['symbols']);
		$url = str_replace(['{symbols}', '{amount}', '{token}'], [$params['symbols'], $params['amount'], self::SK], self::API_CONVERSION_URL);

		$result = remote_request($url);

		$data = $result['response'];

        if (($data == 'Unknown symbol')||($data == '[]')) {
			return false;
		}
		return json_decode($data, true);
	}


}
