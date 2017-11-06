<?php
/**
 * Created by PhpStorm.
 * User: tayfun
 * Date: 06/11/2017
 * Time: 15:18
 */

namespace Baytemizel\Validator\Response;

use JMS\Serializer\Annotation as Serializer;
use Baytemizel\Validator\Service\Translator;

class ValidatorResponse
{
    /**
     * @Serializer\Exclude()
     */
    public $translator;

    public $isValid = false;

    public $code;

    public $result = [];

    /**
     * ValidatorResponse constructor.
     *
     * @param string $code
     * @param array|null $result
     */
    public function __construct(string $code = "500", array $result = null)
    {
        $this->translator = new Translator("validator_responses");

        $this->code = $code;

        if (! is_null($result) || count($result) > 0) {
            foreach ($result as $key => $val) {
                $this->addResult($key, $val);
            }
        }

    }

    /**
     * @param string $key
     * @param string $code
     * @param array $replacement
     */
    public function addResult(string $key, string $code, array $replacement = [])
    {
        $this->result[$key][$code] = $this->getMessageOfCode($code, $replacement);
    }

    /**
     * @return int
     */
    public function getCode()
    {
        return $this->code;
    }

    /**
     * @param string $code
     */
    public function setCode(string $code)
    {
        $this->code = $code;
    }

    /**
     * @return null
     */
    public function getResult()
    {
        return $this->result;
    }

    /**
     * @param $code
     * @param array $replacements
     * @return \InvalidArgumentException|mixed
     */
    public function getMessageOfCode($code, array $replacements = [])
    {
        return $this->translator->getTranslation($code, $replacements);
    }

    /**
     * @param $result
     * @param $code
     */
    public function setManuelResult($result, $code)
    {
        $this->result = $result;
        $this->code = $code;
    }
}