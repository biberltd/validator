<?php

namespace Baytemizel\Validator\Service;


class Validator
{
    public $container;

    protected $data = [];

    public $response;

    protected $credentials = [];

    /**
     * @Serializer\Exclude()
     */
    protected $rules = [];

    /**
     * @Serializer\Exclude()
     */
    private $lowercases = 'abcçdefgğhijklmnoöpqrsştuüvwxyz';

    /**
     * @Serializer\Exclude()
     */
    private $uppercases = 'ABCDEFGĞHIİJKLMNOÖPQRSŞTUÜVWXYZ';

    /**
     * @Serializer\Exclude()
     */
    private $digits = '1234567890';

    /**
     * @Serializer\Exclude()
     */
    private $defaultCredentials = [ 'name' => "required", 'sort' => "required|contains:+id,-id", 'limit' => "required|max:300", 'status' => "required|contains:a,i,d", 'offset' => "required" ];

    public function __construct(Container $container)
    {
        $this->container = $container;
        $this->rules = $this->loadRules();
        $this->response = new ValidatorResponse();
    }

    public function loadRules()
    {
        $yamlContents = Yaml::parse(file_get_contents('../src/AppBundle/Resources/validator_rules.yml'));

        return $yamlContents;
    }

    public function addRule($key, $value)
    {
        $this->rules[$key] = $value;
        return $this;
    }

    public function composeCredentials(string $type)
    {
        $rules = $this->integrateRule($type);
        $this->setCredentials($rules);

        return $this;
    }

    protected function needsArray($value)
    {
        preg_match('#\[(.*?)\]#', $value, $match);
        return (count($match)===2) ? $match[1] : false;
    }

    protected function needsSubRule($value)
    {
        preg_match('#\((.*?)\)#', $value, $match);
        return (count($match)===2) ? $match[1] : false;
    }

    protected function getIndexRule($value, $full = false)
    {
        if($full) {
            preg_match('#index:(.*?)\((.*?)\)#', $value, $match);

            return (count($match) > 0) ? $match : false;
        }

        preg_match('#index:(.*?)\(#', $value, $match);

        return (count($match)===2) ? $match[1] : false;
    }

    public function integrateRule(string $field)
    {
        if(!array_key_exists($field, $this->rules)) throw new \InvalidArgumentException(EDUSYS_API_MSG_500_0002, 500);
        $previous = $this->rules[$field];

        foreach($previous as $f => $v) {
            if(!is_array($v)) {
                if(!$match = $this->needsArray($v))
                {
                    if(mb_substr($v, 0, 1) == "@") {
                        $previous[$f] = $this->integrateRule(mb_substr($v, 1));
                    }else{
                        $previous[$f] = $v;
                    }
                }else{
                    if(mb_substr($match, 0, 1) == "@") {
                        $previous[$f] = ["*" => $this->integrateRule(mb_substr($match, 1))];
                    }else{
                        $previous[$f] = ["*" => $match];
                    }
                }
            }elseif(is_array($v) && count($v) > 0) {
                $mergev=[];
                foreach($v as  $value) {
                    $result = [];
                    foreach($value as $x => $y) {
                        if(mb_substr($y, 0, 1) == "@") {
                            $result[$x] = $this->integrateRule(mb_substr($y, 1));
                        }else{
                            $result[$x] = $y;
                        }
                    }
                    $mergev = array_merge($mergev,$result);
                    unset($result);

                }
                $previous[$f] = $mergev;
            }else{
                $previous[$f] = $v;
            }
        }

        return $previous;
    }

    public function implodeData(array $array, string $glue = null, $previous = null)
    {
        $glue = $glue ?? ".";

        foreach ($array as $k => $v) {
            if (is_array($v)) {
                $k = is_null($previous) ? $k : $previous.$glue.$k;
                $this->implodeData($v, ".", $k);
            }else{
                if (is_null($previous)) {
                    $this->addData($k, $v);
                }else{
                    $this->addData($previous.$glue.$k, $v);
                }
            }
        }

        return $this;
    }

    public function implodeCredentials(array $array, string $glue = null, $previous = null)
    {

        $glue = $glue ?? ".";

        foreach ($array as $k => $v) {
            if (is_array($v)) {
                $k = is_null($previous) ? $k : $previous.$glue.$k;
                $this->implodeCredentials($v, ".", $k);
            }else{
                if (is_null($previous)) {
                    $this->addCredential($k, $v);
                }else{
                    $this->addCredential($previous.$glue.$k, $v);
                }
            }
        }

        return $this;
    }

    public function extendedExplode(array $array, string $glue = null, $output = "array")
    {
        $glue = $glue ?? ".";
        $data = [];
        foreach($array as $key => $value) {
            $explode = explode($glue, $key);
            if(count($explode) > 1) {
                foreach($explode as $v) {
                    $data[$key][] = $v;
                }
            }

            $data[$key] = $value;
        }

        return $data;
    }

    public function treeBuilder($data, $reverse = false)
    {
        if($reverse == false)
        {
            if(!is_array($data)) throw new \InvalidArgumentException(EDUSYS_API_MSG_500_0003, 500);
            $result = implode(".", $this->insider($data));
        }else{
            $result = "";
        }

        return $result;
    }

    protected function insider($data, $previous = null)
    {
        $previous = $previous ?? [];
        foreach($data as $key => $value) {
            $previous[] = $key;
            if(!is_string($value)) {
                $previous = $this->insider($value, $previous);
            }
        }

        return $previous;
    }

    public function injectExpectation($expectations, $expectation)
    {
        $expectations = explode('|', $expectations);
        $expectations[] = $expectation;

        return implode('|', $expectations);
    }

    public function explodeExpectations($expectations)
    {
        $result = [];

        if($indexRule = $this->getIndexRule($expectations, true)) {
            array_push($result, $indexRule[0]);
            $expectationsWithoutIndexRule = str_replace($indexRule[0], '', $expectations);
            $expectationsWithoutIndexRule = trim($expectationsWithoutIndexRule, '|');
            $result = array_merge($result, explode('|', $expectationsWithoutIndexRule));

        }else{
            $result = explode('|', $expectations);
        }

        return $result;
    }

    public function adaptExpectations(array $data, $expectations)
    {
        $result = [];
        $expectations = $this->explodeExpectations($expectations);

        if(is_array($data) && count($data) > 0) {

            foreach($data as $key => $value) {
                foreach($expectations as $expectation) {

                    if($indexRule = $this->getIndexRule($expectation)) {
                        $subRules = $this->needsSubRule($expectation);
                        if(!$subRules) throw new InvalidDataException();
                        if($indexRule == $key) {
                            $response = array_key_exists($key, $result) ? $this->injectExpectation($result[$key], $subRules) : $subRules;
                        }else{
                            $response = null;
                        }
                    }else{
                        $response = array_key_exists($key, $result) ? $this->injectExpectation($result[$key], $expectation) : $expectation;
                    }
                    $result[$key] = trim($response, '|');
                }
            }

        }

        return $result;
    }

    public function validate($request, array $configs = null, string $requestType = null, $previous = null)
    {
        if($request instanceof Request) {
            $requestType = $requestType ?? "query";
            $preparedData = $this->prepareData($request, $requestType);
            $requestedData = $preparedData;
        } elseif(is_array($request)) {
            $requestedData = $request;
        } else {
            throw new InvalidDataException();
        }

        if(!is_null($configs) && count($configs) > 0) {
            $credentials = $configs;
        }else{
            $credentials = $this->getCredentials();
        }

        foreach($credentials as $credential => $expectations) {

            if(is_array($expectations) && count($expectations) > 0) {

                if(array_key_exists('*', $expectations)) {
                    if(array_key_exists($credential, $requestedData)) {
                        // TODO: Checkpoint
                        if(is_array($expectations['*'])) {
                            $this->validate($requestedData[$credential], $expectations['*']);
                        }else{
                            $expectations = $this->adaptExpectations($requestedData[$credential], $expectations["*"]);
                            foreach($expectations as $k => $v) {
                                $this->validateValues($v, $requestedData[$credential], $k);
                            }
                        }


                    }
                }else{
                    $this->validateValues($expectations, $requestedData[$credential], $credential);
                }

            }else{
                $this->validateValues($expectations, $requestedData, $credential);
            }

        }

        if(!count($this->response->getResult())) {
            $this->response->isValid = true;
            $this->response->code = 200;
        }
        dump($this);die;
        return $this;
    }

    /**
     * @param string|array $expectations
     * @param array $requestedData
     * @param string $credential
     *
     * @return $this
     */
    private function validateValues($expectations, $requestedData, $credential, $previous = null)
    {
        if(is_string($expectations)) {
            $expectations = explode("|", $expectations);
        }

        foreach($expectations as $expectation)
        {
            $expectation = $this->optimize($expectation);

            switch(is_array($expectation) ? $expectation[0] : $expectation)
            {
                case "required":
                    $this->isRequired($requestedData, $credential);
                    break;
                case "string":
                    if(!isset($requestedData[$credential]))
                        break;
                    $this->isString($requestedData[$credential], $credential);
                    break;
                case "integer":
                    if(!isset($requestedData[$credential]))
                        break;
                    $this->isInteger($requestedData[$credential], $credential);
                    break;
                case "boolean":
                    if(!isset($requestedData[$credential]))
                        break;
                    $expectation = $expectation[1];
                    $this->isBoolean($requestedData[$credential], $credential, $expectation);
                    break;
                case "min":
                    if(!isset($requestedData[$credential]))
                        break;
                    $expectation = $expectation[1];
                    $this->isMin($requestedData[$credential], $credential, $expectation);
                    break;
                case "max":
                    if(!isset($requestedData[$credential]))
                        break;
                    $expectation = $expectation[1];
                    $this->isMax($requestedData[$credential], $credential, $expectation);
                    break;
                case "between":
                    if(!isset($requestedData[$credential]))
                        break;
                    $expectation = explode(',', $expectation[1]);
                    $this->isBetween($requestedData[$credential], $credential, $expectation);
                    break;
                case "not-between":
                    if(!isset($requestedData[$credential]))
                        break;
                    $expectation = explode(',', $expectation[1]);
                    $this->isNotBetween($requestedData[$credential], $credential, $expectation);
                    break;
                case "email":
                    if(!isset($requestedData[$credential]))
                        break;
                    $this->isEmail($requestedData[$credential], $credential);
                    break;
                case "contains":
                    if(!isset($requestedData[$credential]))
                        break;
                    $expectation = explode(',', $expectation[1]);
                    $this->isContains($requestedData[$credential], $credential, $expectation);
                    break;
                case "not-contains":
                    if(!isset($requestedData[$credential]))
                        break;
                    $expectation = explode(',', $expectation[1]);
                    $this->isNotContains($requestedData[$credential], $credential, $expectation);
                    break; // TODO: isNotContainsArray & isContainsArray Eklenecek.

                case "password":
                    if(!isset($requestedData[$credential]))
                        break;

                    $length = (is_array($expectation)) ? $expectation[1] : 6;
                    $this->lengthOf($requestedData[$credential], $length, $credential)
                        ->isUppercase($requestedData[$credential], $credential)
                        ->isLowercase($requestedData[$credential], $credential)
                        ->isDigit($requestedData[$credential], $credential);
                    break;
                case "maxInlineLetter":
                    if(!isset($requestedData[$credential]))
                        break;
                    $length = (is_array($expectation) && count($expectation) > 0) ? $expectation[1] : 3;
                    $this->isMaxInlineLetter($requestedData[$credential], $length, $credential);
                    break;
                case "maxInlineDigit":
                    if(!isset($requestedData[$credential]))
                        break;
                    $length = (is_array($expectation) && count($expectation) > 0) ? $expectation[1] : 3;
                    $this->isMaxInlineDigit($requestedData, $length, $credential);
                    break;
                case "maxRepeatedLetter":
                    if(!isset($requestedData[$credential]))
                        break;
                    $length = (is_array($expectation) && count($expectation) > 0) ? $expectation[1] : 3;
                    $this->maxRepeatedLetter($requestedData[$credential], $length, $credential);
                    break;
                case "maxRepeatedDigit":
                    if(!isset($requestedData[$credential]))
                        break;
                    $length = (is_array($expectation) && count($expectation) > 0) ? $expectation[1] : 3;
                    $this->isMaxRepeatedDigit($requestedData[$credential], $length, $credential);
                    break;
                case "maxLetter":
                    if(!isset($requestedData[$credential]))
                        break;
                    $length = (is_array($expectation) && count($expectation) > 0) ? $expectation[1] : 4;
                    $this->isMaxLetter($requestedData[$credential], $length, $credential);
                    break;
                case "maxDigit":
                    if(!isset($requestedData[$credential]))
                        break;

                    $length = (is_array($expectation) && count($expectation) > 0) ? $expectation[1] : 4;
                    $this->isMaxDigit($requestedData[$credential], $length, $credential);
                    break;
                case "confirm":
                    if(!isset($requestedData[$credential]))
                        break;
                    $key = (is_array($expectation) && count($expectation) > 0) ? $expectation[1] : $credential . "Confirm";
                    if(!isset($requestedData[$key]))
                    {
                        $this->response->addResult($key, "404.0000", [ $key ]);
                        break;
                    }
                    if($requestedData[$credential] !== $requestedData[$key])
                    {
                        $this->response->addResult($credential, "510.0010");
                    }
                    break;
                case "ssn":
                    if(!isset($requestedData[$credential]))
                        break;
                    $length = (is_array($expectation)) ? $expectation[1] : 11;
                    $this->isSsn($requestedData[$credential], $credential);
                    break;
                case "equalTo":
                    if(!isset($requestedData[$credential]))
                        break;
                    if(!is_array($expectation))
                        $this->response->addResult($credential, "404.0001", [ $expectation ]);
                    if(is_array($expectation) && $expectation[1] !== $requestedData[$credential])
                    {
                        $this->response->addResult($credential, "500.0008", [ $expectation[1] ]);
                    }
                    break;

                default:
                    break;
            }
        }

        return $this;
    }

    public function getCredentials()
    {
        return $this->credentials;
    }

    public function setCredentials(array $credentials)
    {
        $this->credentials = $credentials;

        return $this;
    }

    public function addCredential($key, $value)
    {
        $this->credentials[$key] = $value;

        return $this;
    }

    public function addCredentials(array $credentials)
    {

        foreach($credentials as $name => $credential) {
            if(is_string($credential)) {
                array_unshift($this->credentials[$name], $credential);
            }

            if(is_array($credential)) {
                $this->credentials[$name] = array_merge($this->credentials[$name], $credential);
            }
        }

        return $this;
    }

    public function getData()
    {
        return $this->data;
    }

    public function addData($key, $value)
    {
        $this->data[$key] = $value;

        return $this;
    }

    public function setData(array $data)
    {
        $this->data = $data;

        return $this;
    }

    public function prepareData(Request $request, $requestType)
    {
        $data = [];

        foreach($request->{$requestType}->keys() as $key) {
            $qData = $request->{$requestType}->get($key);

            if(is_array($qData)) {
                foreach($qData as $d) {
                    $data[$key][] = $d;
                }
            }

            if(is_string($qData)) {
                $data[$key] = trim($qData);
            }

            if(is_int($qData) || is_numeric($qData)) {
                $data[$key] = $qData;
            }

        }

        return $data;
    }

    public function optimize($expectation)
    {
        $pattern = '/:/';

        if(preg_match($pattern, $expectation)) {
            return explode(':', $expectation);
        }

        return $expectation;
    }

    public function match(array $matches)
    {
        $data = [];

        foreach($matches as $match) {
            $data[$match[1]] = $match[0];
        }

        return $data;
    }

    public function hasErrors()
    {
        if($this->response->isValid) {
            return false;
        }

        return $this->response->getResult();
    }

    public function isRequired($data, string $field)
    {
        if(!in_array($field, array_keys($data)) || empty($data[$field])) {
            $this->response->addResult($field, "500.0001");
        }

        return $this;
    }

    public function isString($data, string $field)
    {
        if(!is_string($data)) {
            $this->response->addResult($field, "500.0009", [ "String" ]);
        }

        return $this;
    }

    public function isInteger($data, string $field)
    {
        if(!is_int($data) && !is_numeric($data)) {
            $this->response->addResult($field, "500.0009", [ "Integer" ]);
        }

        return $this;
    }

    public function isBoolean($data, string $field, $expectation)
    {
        $expectation = strtolower($expectation);

        if($expectation == "false") {
            if(isset($data[$field]) && !empty($data[$field])) $this->response->addResult($field, "500.0010", [ $expectation ]);
        }

        if($expectation == "true") {
            if(!isset($data[$field])) $this->response->addResult($field, "500.0011", [ $expectation ]);
        }

        return $this;
    }

    public function isMin($data, string $field, $expectation)
    {
        if(!RValidator::notOptional()->intVal()->min($expectation)->validate($data)) {
            $this->response->addResult($field, "500.0004", [ $expectation ]);
        }

        return $this;
    }

    public function isMax($data, string $field, $expectation)
    {
        if(!RValidator::notOptional()->intVal()->max($expectation)->validate($data)) {
            $this->response->addResult($field, "500.0005", [ $expectation ]);
        }

        return $this;
    }

    public function isBetween($data, string $field, array $expectation)
    {
        if(!RValidator::notOptional()->intVal()->positive()->between($expectation[0], $expectation[1])->validate($data)) {
            $this->response->addResult($field, "500.0006", [ $expectation[0], $expectation[1] ]);
        }

        return $this;
    }

    public function isNotBetween($data, string $field, array $expectation)
    {
        if(RValidator::notOptional()->intVal()->positive()->between($expectation[0], $expectation[1])->validate($data)) {
            $this->response->addResult($field, "500.0007", [ $expectation[0], $expectation[1] ]);
        }

        return $this;
    }

    public function isEmail($data, string $field)
    {
        if(!RValidator::notOptional()->email()->validate($data)) {
            $this->response->addResult($field, "500.0003");
        }

        return $this;
    }

    public function isContains(array $data, string $field, $expectation)
    {
        if(count($data) > 0) {
            foreach($data as $val) {
                if(!RValidator::notOptional()->contains($val)->validate($expectation)) {
                    $this->response->addResult($field, "500.0003");
                }
            }
        }

        return $this;
    }

    public function isNotContains(array $data, $field, $expectation)
    {
        if(count($data) > 0) {
            foreach($data as $val) {
                if(RValidator::notOptional()->contains($val)->validate($expectation)) {
                    $this->response->addResult($field, "500.0003");
                }
            }
        }

        return $this;
    }

    public function lengthOf($data, int $length = null, $field)
    {
        $length = $length ?? 6;
        if(!RValidator::notOptional()->stringType()->length($length, null)->validate($data)) {
            $this->response->addResult($field, "510.0000", [ $length ]);
        }

        return $this;
    }

    public function isUppercase($data, $field)
    {
        $uppercasePattern = '/[A-Z]/';
        if(!preg_match($uppercasePattern, $data)) {
            $this->response->addResult($field, "510.0001");
        }

        return $this;
    }

    public function isLowercase($data, $field)
    {
        $lowercasePattern = '/[a-z]/';
        if(!preg_match($lowercasePattern, $data)) {
            $this->response->addResult($field, "510.0002");
        }

        return $this;
    }

    public function isDigit($data, $field)
    {
        $digitPattern = '/[0-9]/';
        if(!preg_match($digitPattern, $data)) {
            $this->response->addResult($field, "510.0003");
        }

        return $this;
    }

    public function isMaxInlineLetter($data, int $length = null, $field)
    {
        $length = $length ?? 2;
        for($i = 0; $i < mb_strlen($this->lowercases) - ($length - 1); $i++) (strpos(mb_strtolower($data), mb_substr($this->lowercases, $i, $length)) > -1) ? $this->response->addResult($field, "510.0004") : null;

        return $this;
    }

    public function isMaxInlineDigit($data, int $length = null, $field)
    {
        $length = $length ?? 2;
        for($i = 0; $i < strlen($this->digits) - ($length - 1); $i++) (strpos($data, substr($this->digits, $i, $length)) > -1) ? $this->response->addResult($field, "510.0005") : null;

        return $this;
    }

    public function maxRepeatedLetter($data, int $length = null, $field)
    {
        $length = $length ?? 2;
        foreach(str_split($this->lowercases . $this->uppercases) as $char) (strpos($data, str_repeat($char, $length)) > -1) ? $this->response->addResult($field, "510.0006") : null;

        return $this;
    }

    public function isMaxRepeatedDigit($data, int $length = null, $field)
    {
        $length = $length ?? 2;
        foreach(str_split($this->digits) as $char) (strpos($data, str_repeat($char, $length)) > -1) ? $this->response->addResult($field, "510.0007") : null;

        return $this;
    }

    public function isMaxLetter($data, int $length = null, $field)
    {
        $length = $length ?? 4;
        preg_match_all('/[a-zA-Z]/', $data, $matches);
        $countValues = array_count_values($matches[0]);
        foreach($countValues as $value => $times) {
            if($times > $length) {
                $this->response->addResult($field, "510.0008");
            }
        }

        return $this;
    }

    public function isMaxDigit($data, int $length = null, $field)
    {
        $length = $length ?? 4;
        preg_match_all('/[0-9]/', $data, $matches);
        $countValues = array_count_values($matches[0]);
        foreach($countValues as $value => $times) {
            if($times > $length) {
                $this->response->addResult($field, "510.0009");
            }
        }

        return $this;
    }

    public function isSsn($data, $field, string $type = 'tckn')
    {
        if($type == 'tckn') {

            if(strlen($data) !== '11') {
                $this->response->addResult($field, "500.0010", [ 11 ]);
            }

            if(!$this->validateTCKN($data)) {
                $this->response->addResult($field, "500.000");
            }

        }

        return $this;

    }

    public function validateTCKN($data)
    {
        $realNumbers = mb_substr((string) $data, 0, 9);
        $arr = str_split($realNumbers);

        $ten = ((7 * ($arr[0] + $arr[2] + $arr[4] + $arr[6] + $arr[8])) - ($arr[1] + $arr[3] + $arr[5] + $arr[7])) % 10;
        $newNumber = $realNumbers . $ten;

        $eleven = array_sum(str_split($newNumber)) % 10;

        $lastNumber = $newNumber . $eleven;

        if($data == $lastNumber) {
            return true;
        }

        return false;
    }
}