<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use App\Entity\MeteoWeatherCodes;
use App\Entity\Product;
use Faker\Factory;


class ProductRecommendationsApi extends AbstractController
{


	/**
	 * @Route("/api/products/recommended/{city}", name="product_recommendation")
	 */

	public function showRecommendedProductsApi($city, $limit = 10)
    {

    	$recommendedProducts = $this->getRecommendedProductsApi($city, $limit);

    	return new Response($recommendedProducts);

    }

	/**
	 * @Route("/faker/{howManyDataEntriesToInsert}", name="faker_data")
	 */

	public function addFakeProductDataToDb($howManyDataEntriesToInsert = 0)
    {
    	if (is_numeric($howManyDataEntriesToInsert) && $howManyDataEntriesToInsert >= 1) {
	    	$fakerData = $this->generateTestDataFaker($howManyDataEntriesToInsert);

	    	if ($fakerData) {
		    	$this->populateTestDataFakerToDb($fakerData);

		    	return new Response('inserted ' . $howManyDataEntriesToInsert . ' new fake product entries into DB!');
		    }
	    }
    }


    public function getRecommendedProductsApi($city, $limit)
    {

    	$city = $this->formatCity($city);

    	$recommendedProducts = [];

    	$weather = $this->parseCurrentWeatherDataFromMeteo($city);

    	$weather['weatherSource'] = 'LHMT'; 


    	if ($weather['meteoError'] == '') {
	    	$recommendedProducts = $this->getRecommendedProductsByWeatherFromDb($weather['currentWeather'], $limit);
	    }

    	$recommendedProducts = array_merge($weather, ['recommendedProducts' => $recommendedProducts]);

    	$recommendedProducts = json_encode($recommendedProducts);

        return $recommendedProducts;
    }	

  	public function formatCity($city)
    {

    	preg_match('|^[^a-z]*([a-z]+)[^a-z]*([a-z]+)?|is', $city, $m);

    	if (!empty($m[2])) {
			$city = $m[1] . '-' . $m[2];
    	} else {
			$city = $m[1];
    	}


    	$city = strtolower($city);

        return $city;
    }

    public function getRecommendedProductsByWeatherFromDb($weather, $limit = 0)
    {


		$entityManager = $this->getDoctrine()->getManager();   
        $conn = $entityManager->getConnection();

        $sql = "SELECT `sku`, `name`, `price` FROM `product` WHERE FIND_IN_SET('" . $weather . "', weather)";

        if ($limit > 0) {
        	$sql .= ' LIMIT ' . $limit;
        }

        $stmt = $conn->prepare($sql);
        $stmt->execute();

        $products = $stmt->fetchAll();

		return $products;

    }

    public function getMeteoWeatherCodesFromDb()
    {

    	$weatherCodesRepository = $this->getDoctrine()->getRepository(MeteoWeatherCodes::class);

    	$weatherCodesMeteoObj = $weatherCodesRepository->findAll();

		foreach ($weatherCodesMeteoObj as $key => $code) {
			$weatherCodesMeteo[$key] = $code->getCode();			
		}

		return $weatherCodesMeteo;

    }


    public function parseCurrentWeatherDataFromMeteo($city)
    {

    	$data = $this->getWeatherFromMeteo($city);
    	$meteoWeatherArray = [];

    	if ($data == 'too_many_requests_per_day') {

				$meteoNecessaryFields = [
				'city' => $city, 
				'currentWeather' => 'na',
				'meteoError' => 'Too many requests per day'
			   ];

    	} elseif (!empty($data)) {

    		$meteoWeatherArray = json_decode($data, true);

    	
			$meteoNecessaryFields = [
				'city' => $meteoWeatherArray['place']['name'], 
				'currentWeather' => $meteoWeatherArray['forecastTimestamps'][0]['conditionCode'],
				'meteoError' => ''
			   ];

		} else {

			$meteoNecessaryFields = [
				'city' => $city, 
				'currentWeather' => 'na',
				'meteoError' => 'No city'
			   ];
		}


		return $meteoNecessaryFields;							   
        
    }

    public function getWeatherFromMeteo($city)
    {


    	$meteoApiUrl = 'https://api.meteo.lt/v1/places/%s/forecasts/long-term';
		$meteoApiUrlFormatted = sprintf($meteoApiUrl, $city);

		// Meteo API doesn't allow more than 20,000 requests per day
		if ($this->countRequestsToMeteoPerDay() > 19500) {

			$meteoWeatherJson = 'too_many_requests_per_day';

			return $meteoWeatherJson; 

		}

		$meteoWeatherJson = @file_get_contents($meteoApiUrlFormatted);

        return $meteoWeatherJson; 
    }

    public function countRequestsToMeteoPerDay()
    {

    	$meteoCountFileName = 'requests_cnt_to_meteo.txt';
    	$count = 0;

    	$content = $this->getFileContent(dirname(__DIR__) . '/../data/' . $meteoCountFileName);

    	if (!empty($content)) {

    		$content = explode("\n", $content);

			array_unshift($content, time());

    		foreach ($content as $k => $time) {

	    		if ($time > (time() - 86400)) {
					$count++; 
	    		} else {
	    			unset($content[$k]);
	    		}
    		}

    	} else {
			$content = [time()];
			$count++;
    	}


		$this->putContentToFile($content, dirname(__DIR__) . '/../data/' . $meteoCountFileName, true);

		return $count;

    }


    public function getFileContent($fileName)
    {

    	$content = '';

    	if (file_exists($fileName)) {
    		$content = file_get_contents($fileName);
    	} 

    	return $content;

    }

    public function putContentToFile($content, $fileName , $array = false)
    {

    	if ($array === true) {

    		file_put_contents($fileName, '');

    		foreach ($content as $contentLine) {
    			file_put_contents($fileName, $contentLine . "\n", FILE_APPEND);
    		}

    	} else {
    		file_put_contents($fileName, $content);
    	}
    	

    }

    public function generateTestDataFaker($howMany)
    {

    	$weatherCodesMeteo = $this->getMeteoWeatherCodesFromDb();

		$faker = Factory::create('en_US');

		$fakeDataArrays = [];

		for ($i=0; $i < $howMany; $i++) { 

			$faker->unique(true)->randomElement($weatherCodesMeteo);
			$fakeData = [];

			$fakeData['sku'] = $faker->lexify('???') . '-' . $faker->numberBetween(1, 9000);
			$fakeData['name'] = $faker->words(2, true);
			$fakeData['price'] = $faker->randomFloat(2, 0, 100);

			$fakeData['weather'] = $faker->randomElements($weatherCodesMeteo, $faker->numberBetween(1, 3));			

			$fakeDataArrays[] = $fakeData;
		}

		return $fakeDataArrays;
    	

    }

    public function populateTestDataFakerToDb($data)
    {

    	$fakeDataArrays = $data;
    	$entityManager = $this->getDoctrine()->getManager();


		foreach ($fakeDataArrays as $fakeDataArray) {

			$product = new Product();

			foreach ($fakeDataArray as $name => $value) {

				$set = 'set' . ucfirst($name);
		        $product->$set($value);		        
		        
			}

			 $entityManager->persist($product);
			 $entityManager->flush();

		}
    }

}