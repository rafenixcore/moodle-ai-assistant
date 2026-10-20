<?php
// This file is part of the local_mcpchat plugin for Moodle.
namespace local_mcpchat;

defined('MOODLE_INTERNAL') || die();

/**
 * Cliente minimo de la API de DeepSeek (chat completions con tools).
 * Endpoint y formato compatibles con OpenAI.
 */
class deepseek {

    const URL = 'https://api.deepseek.com/chat/completions';

    /**
     * @param array $messages [{role, content}...] — ya incluye el system.
     * @param array $tools [{name, description, parameters}...]
     * @return array ['content' => ?string, 'tool_calls' => array]
     * @throws \moodle_exception
     */
    public function chat(array $messages, array $tools, string $apikey,
                         string $model): array {
        $payload = array(
            'model' => $model,
            'messages' => $messages,
            'temperature' => 0.1,
        );
        if ($tools) {
            $payload['tools'] = array_map(function (array $t): array {
                $fn = $t;
                if (isset($fn['parameters']) && is_array($fn['parameters'])) {
                    // Normalizar el schema para la API (gotcha de
                    // json_decode assoc): ver normalizar_schema().
                    $fn['parameters'] = self::normalizar_schema($fn['parameters']);
                }
                return array('type' => 'function', 'function' => $fn);
            }, $tools);
            $payload['tool_choice'] = 'auto';
        }

        $ch = curl_init(self::URL);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apikey,
            ),
            CURLOPT_TIMEOUT => 30,
        ));
        $resp = curl_exec($ch);
        $httpcode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) {
            throw new \moodle_exception('deepseek_curl', 'local_mcpchat', '', $err);
        }
        if ($httpcode !== 200) {
            throw new \moodle_exception('deepseek_http', 'local_mcpchat', '',
                "HTTP $httpcode: " . substr((string) $resp, 0, 300));
        }
        $data = json_decode((string) $resp, true);
        if (!isset($data['choices'][0]['message'])) {
            throw new \moodle_exception('deepseek_invalid', 'local_mcpchat', '',
                substr((string) $resp, 0, 300));
        }
        $msg = $data['choices'][0]['message'];
        // Frontera con API paga: filtrar tool_calls malformadas. Solo se pasan
        // al bot las que tengan id y function.name/function.arguments no vacios.
        $toolcalls = array();
        foreach ($msg['tool_calls'] ?? array() as $tc) {
            if (empty($tc['id']) || empty($tc['function']['name'])
                    || empty($tc['function']['arguments'])) {
                continue;
            }
            $toolcalls[] = $tc;
        }
        if (!empty($msg['tool_calls']) && !$toolcalls) {
            throw new \moodle_exception('deepseek_invalid', 'local_mcpchat', '',
                substr((string) $resp, 0, 300));
        }
        return array(
            'content' => $msg['content'] ?? null,
            'tool_calls' => $toolcalls,
        );
    }

    /**
     * Normaliza un schema JSON para el payload de DeepSeek.
     *
     * gotcha: json_decode(..., true) convierte {} en array() y json_encode
     * serializa un array vacio como [] (lista) en vez de {} (objeto).
     * DeepSeek valida el schema estrictamente y rechaza con HTTP 400
     * ("[] is not of type object") un properties vacio, un items vacio,
     * etc. Este helper convierte a \stdClass (que json_encode emite como
     * {}) SOLO los arrays vacios en posiciones de OBJETO del JSON Schema;
     * las listas vacias en otras posiciones (required, enum, ...) se
     * mantienen como array.
     *
     * @param mixed $node Nodo del schema (array asociativo o escalar).
     * @return mixed Nodo equivalente serializable para la API.
     */
    private static function normalizar_schema($node) {
        if (!is_array($node)) {
            return $node;
        }
        if ($node === array()) {
            // Solo se llega aqui desde una posicion de objeto del schema
            // (la decide el caller): se emite {}.
            return new \stdClass();
        }
        // Keys del JSON Schema cuyo valor es SIEMPRE un objeto (o un mapa/
        // lista de objetos): vacias deben serializar como {}.
        $objkeys = array('properties', 'items', 'additionalProperties',
            'patternProperties', 'propertyNames', 'definitions', '$defs',
            'allOf', 'anyOf', 'oneOf', 'not', 'if', 'then', 'else',
            'contains', 'dependentSchemas');
        $out = array();
        foreach ($node as $k => $v) {
            if (in_array($k, $objkeys, true)) {
                $out[$k] = self::normalizar_posicion_objeto($v);
            } else {
                // Posicion NO de objeto: un array vacio (required, enum,
                // type como lista...) se mantiene como [].
                $out[$k] = (is_array($v) && $v === array())
                    ? $v : self::normalizar_schema($v);
            }
        }
        return $out;
    }

    /**
     * Normaliza el valor de una key de objeto del JSON Schema: un array
     * vacio aqui es un objeto vacio {} (nunca una lista []).
     *
     * @param mixed $v Valor de la key (mapa name=>schema, lista de schemas o vacio).
     * @return mixed
     */
    private static function normalizar_posicion_objeto($v) {
        if (!is_array($v)) {
            return $v;
        }
        if ($v === array()) {
            return new \stdClass();
        }
        // Objeto no vacio: los elementos (schemas anidados) se normalizan
        // recursivamente.
        return self::normalizar_schema($v);
    }
}
