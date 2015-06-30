<?php

namespace SA\CpeSdk;

class CpeJsonValidator
{
    public function validate_json(
        $json,
        $schemas_name,
        $schemas_path)
    {
        $retriever = new JsonSchema\Uri\UriRetriever;
        $json_schemas = $retriever->retrieve('file://' . $schemas_path . "/$schemas");

        $refResolver = new JsonSchema\RefResolver($retriever);
        $refResolver->resolve($json_schemas, 'file://' . $schemas_path . "/");

        $validator = new JsonSchema\Validator();
        $validator->check($json, $json_schemas);

        if ($validator->isValid())
            return false;
    
        $details = "";
        foreach ($validator->getErrors() as $error) {
            $details .= sprintf("[%s] %s\n", $error['property'], $error['message']);
        }
    
        return $details;
    }
}