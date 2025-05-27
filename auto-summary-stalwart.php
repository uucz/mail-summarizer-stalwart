<?php
/**
* Stalwart MTA Hook for Email Summarization and Translation using OpenAI
* Har-Kuun @ Github
* <https://github.com/Har-Kuun/mail-summary-stalwart/>
*/

// --- Configuration ---
$openai_api_key = "sk-xxxxxxxxxxxx"; // Replace with your actual OpenAI API key
$openai_model = "changpt-4o";
$openai_api_url = "<https://openai.com/v1>";
$summary_max_tokens = 500; // Max tokens for the summary (1-2 sentences should be less)
$summary_temperature = 0.5; // Controls randomness, lower is more deterministic
$curl_timeout = 15; // Seconds to wait for OpenAI API response
$enable_translation = true; // 启用邮件全文翻译功能
$translation_max_tokens = 4000; // 邮件全文翻译的最大tokens（大幅增加以容纳长邮件）
$translation_temperature = 0.3; // 翻译温度，较低以保证准确性

function get_email_summary(string $email_content, string $api_key, string $model, string $url, int $max_tokens, float $temperature, int $timeout): ?string {
    // Construct the prompt for OpenAI
    $prompt_text = "Summarize the following email content in 1-2 concise sentences in Chinese. " .
                   "Focus on the main topic and key information. " .
                   "请用简体中文总结以下邮件内容，1-2句话即可：\\n\\n" . strip_tags($email_content);

    $data = [
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => 'You are an AI assistant that summarizes email content concisely in Chinese. 你是一个用中文简洁总结邮件内容的AI助手。'],
            ['role' => 'user', 'content' => $prompt_text]
        ],
        'max_tokens' => $max_tokens,
        'temperature' => $temperature,
        'n' => 1,
        'stop' => null
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $api_key
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout / 2);

    $response_json = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($curl_error) {
        error_log("MTA Hook - OpenAI API cURL Error: " . $curl_error);
        return null;
    }

    if ($http_code !== 200) {
        error_log("MTA Hook - OpenAI API HTTP Error: " . $http_code . " - Response: " . $response_json);
        return null;
    }

    $response_data = json_decode($response_json, true);

    if (isset($response_data['choices'][0]['message']['content'])) {
        return trim($response_data['choices'][0]['message']['content']);
    } else {
        error_log("MTA Hook - OpenAI API Unexpected response format: " . $response_json);
        return null;
    }
}

// 新增：翻译邮件全文的函数
function translate_email_content(string $email_content, string $api_key, string $model, string $url, int $max_tokens, float $temperature, int $timeout): ?string {
    // 清理HTML标签以获得纯文本
    $clean_content = strip_tags($email_content);

    // 如果内容太短，不值得翻译
    if (strlen(trim($clean_content)) < 10) {
        return null;
    }

    // 构建翻译提示
    $prompt_text = "请将以下邮件内容准确翻译成简体中文。保持原文的语气和格式，只翻译文本内容：\\n\\n" . $clean_content;

    $data = [
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => '你是一个专业的邮件翻译助手。请准确地将邮件内容翻译成简体中文，保持原文的语气、格式和专业性。'],
            ['role' => 'user', 'content' => $prompt_text]
        ],
        'max_tokens' => $max_tokens,
        'temperature' => $temperature,
        'n' => 1,
        'stop' => null
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $api_key
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout * 2); // 给翻译更多时间
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);

    $response_json = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($curl_error) {
        error_log("MTA Hook - Translation API cURL Error: " . $curl_error);
        return null;
    }

    if ($http_code !== 200) {
        error_log("MTA Hook - Translation API HTTP Error: " . $http_code . " - Response: " . $response_json);
        return null;
    }

    $response_data = json_decode($response_json, true);

    if (isset($response_data['choices'][0]['message']['content'])) {
        return trim($response_data['choices'][0]['message']['content']);
    } else {
        error_log("MTA Hook - Translation API Unexpected response format: " . $response_json);
        return null;
    }
}

// --- Main MTA Hook Logic ---

// Set content type for the response to Stalwart
header('Content-Type: application/json');

// Get the raw POST data from Stalwart
$json_input = file_get_contents('php://input');

// Decode the JSON request
$request_data = json_decode($json_input, true);

// Basic validation of the incoming request
if ($request_data === null || !isset($request_data['message']['contents']) || !isset($request_data['envelope']['from']['address'])) {
    error_log("MTA Hook - Invalid or incomplete request data: " . $json_input);
    echo json_encode([
        'action' => 'accept',
        'response' => [
            'status' => 250,
            'enhancedStatus' => '2.0.0',
            'message' => 'Message accepted (input error, no summary attempted)',
            'disconnect' => false
        ],
        'modifications' => []
    ]);
    exit;
}

// Check if the email is likely outgoing
$is_outgoing = false;
if (isset($request_data['context']['sasl']['login']) && !empty($request_data['context']['sasl']['login'])) {
    $is_outgoing = true;
    $summary_status_message = 'Skipped (Outgoing Email)';
}

$original_email_content = $request_data['message']['contents'];
$new_email_content = $original_email_content;
$modifications = [];

// Only attempt to summarize and translate if it's not an outgoing email
if (!$is_outgoing && !empty(trim($original_email_content))) {
    // 获取邮件摘要
    $summary_text = get_email_summary(
        $original_email_content,
        $openai_api_key, $openai_model, $openai_api_url,
        $summary_max_tokens, $summary_temperature, $curl_timeout
    );

    // 获取邮件全文翻译（如果启用）
    $translated_content = null;
    if ($enable_translation) {
        $translated_content = translate_email_content(
            $original_email_content,
            $openai_api_key, $openai_model, $openai_api_url,
            $translation_max_tokens, $translation_temperature, $curl_timeout
        );
    }

    if ($summary_text !== null && !empty(trim($summary_text))) {
        $main_content_type_header_value = '';
        $main_content_type = '';
        $boundary = null;

        // Extract main Content-Type and boundary
        if (isset($request_data['message']['headers'])) {
            foreach ($request_data['message']['headers'] as $header_pair) {
                if (is_array($header_pair) && count($header_pair) === 2 && strtolower($header_pair[0]) === 'content-type') {
                    $main_content_type_header_value = $header_pair[1];
                    $main_content_type = strtolower(trim(explode(';', $main_content_type_header_value)[0]));
                    if (preg_match('/boundary="?([^"]+)"?/i', $main_content_type_header_value, $matches)) {
                        $boundary = $matches[1];
                    }
                    break;
                }
            }
        }

        // 准备摘要和翻译块
        $escaped_summary = htmlspecialchars($summary_text, ENT_QUOTES, 'UTF-8');
        $escaped_translation = ($translated_content !== null) ? htmlspecialchars($translated_content, ENT_QUOTES, 'UTF-8') : '';

        // HTML版本的摘要和翻译块
        $html_summary_block = PHP_EOL . '<div style="background-color: #FFFFE0; padding: 10px; border: 1px solid #E0E0E0; margin-bottom: 15px; font-family: Arial, Helvetica, sans-serif; font-size: 14px; color: #333333; border-radius: 5px;">' .
                              '<p style="margin: 0 0 5px 0; padding: 0; font-weight: bold;">[AI 摘要]</p>' .
                              '<p style="margin: 0; padding: 0; font-style: italic;">' . $escaped_summary . '</p>' .
                              '</div>' . PHP_EOL;

        // 如果有翻译，添加翻译块
        if ($translated_content !== null) {
            $html_summary_block .= '<div style="background-color: #F0F8FF; padding: 10px; border: 1px solid #B0C4DE; margin-bottom: 15px; font-family: Arial, Helvetica, sans-serif; font-size: 14px; color: #333333; border-radius: 5px;">' .
                                  '<p style="margin: 0 0 5px 0; padding: 0; font-weight: bold;">[中文翻译]</p>' .
                                  '<div style="margin: 0; padding: 0; white-space: pre-wrap;">' . $escaped_translation . '</div>' .
                                  '</div>' . PHP_EOL;
        }

        // 纯文本版本的摘要和翻译块
        $plain_text_summary_block = PHP_EOL . "[AI 摘要]" . PHP_EOL .
                                   wordwrap($summary_text, 72, PHP_EOL, true) . PHP_EOL . PHP_EOL .
                                   "------------------------------------" . PHP_EOL . PHP_EOL;

        if ($translated_content !== null) {
            $plain_text_summary_block .= "[中文翻译]" . PHP_EOL .
                                        wordwrap($translated_content, 72, PHP_EOL, true) . PHP_EOL . PHP_EOL .
                                        "------------------------------------" . PHP_EOL . PHP_EOL;
        }

        // --- 注入逻辑（与原代码相同，但使用更新后的块） ---
        if (($main_content_type === 'multipart/alternative' || $main_content_type === 'multipart/mixed' || $main_content_type === 'multipart/related') && $boundary !== null) {
            $delimiter = '--' . $boundary;
            $email_parts = explode($delimiter, $original_email_content);

            $reconstructed_parts = [];
            $reconstructed_parts[] = array_shift($email_parts);

            foreach ($email_parts as $idx => $part_data) {
                if (trim($part_data) === '--') {
                    $reconstructed_parts[] = $part_data;
                    continue;
                }

                $part_leading_whitespace = '';
                if (preg_match('/^(\\s)/', $part_data, $ws_match)) {
                    $part_leading_whitespace = $ws_match[1];
                }
                $content_of_part = substr($part_data, strlen($part_leading_whitespace));

                @list($part_headers_str, $part_body_content) = explode("\\r\\n\\r\\n", $content_of_part, 2);
                if ($part_body_content === null) {
                    $part_body_content = '';
                    if (strpos($content_of_part, "\\r\\n\\r\\n") === false) $part_headers_str = $content_of_part;
                }

                $current_part_content_type = '';
                $current_part_boundary = null;
                if (preg_match('/Content-Type:\\s*([^\\s;]+)/i', $part_headers_str, $ct_match)) {
                    $current_part_content_type = strtolower($ct_match[1]);
                    if (preg_match('/boundary="?([^"]+)"?/i', $part_headers_str, $b_match)) {
                        $current_part_boundary = $b_match[1];
                    }
                }

                $modified_part_body = $part_body_content;

                if ($current_part_content_type === 'text/html') {
                    if (preg_match('/<body[^>]*>/i', $part_body_content, $body_tag_match, PREG_OFFSET_CAPTURE)) {
                        $offset = $body_tag_match[0][1] + strlen($body_tag_match[0][0]);
                        $modified_part_body = substr_replace($part_body_content, $html_summary_block, $offset, 0);
                    } else {
                        $modified_part_body = $html_summary_block . $part_body_content;
                    }
                } elseif ($current_part_content_type === 'text/plain') {
                    $modified_part_body = $plain_text_summary_block . $part_body_content;
                } elseif (($current_part_content_type === 'multipart/alternative' || $current_part_content_type === 'multipart/related') && $current_part_boundary !== null) {
                    // Handle nested multipart
                    $sub_delimiter = '--' . $current_part_boundary;
                    $sub_parts = explode($sub_delimiter, $part_body_content);
                    $reconstructed_sub_parts = [array_shift($sub_parts)];

                    foreach ($sub_parts as $sub_part_data) {
                        if (trim($sub_part_data) === '--') {
                            $reconstructed_sub_parts[] = $sub_part_data;
                            continue;
                        }
                        $sub_part_leading_ws = '';
                        if(preg_match('/^(\\s)/', $sub_part_data, $sws_match)) $sub_part_leading_ws = $sws_match[1];
                        $content_of_sub_part = substr($sub_part_data, strlen($sub_part_leading_ws));

                        @list($sub_part_headers, $sub_part_body) = explode("\\r\\n\\r\\n", $content_of_sub_part, 2);
                        if ($sub_part_body === null) {
                            $sub_part_body = '';
                            if (strpos($content_of_sub_part, "\\r\\n\\r\\n") === false) $sub_part_headers = $content_of_sub_part;
                        }

                        $final_sub_part_body = $sub_part_body;
                        $sub_part_ct_val = '';
                        if (preg_match('/Content-Type:\\s*([^\\s;]+)/i', $sub_part_headers, $sub_ct_match)) {
                            $sub_part_ct_val = strtolower($sub_ct_match[1]);
                        }

                        if ($sub_part_ct_val === 'text/html') {
                            if (preg_match('/<body[^>]*>/i', $sub_part_body, $s_body_match, PREG_OFFSET_CAPTURE)) {
                                $s_offset = $s_body_match[0][1] + strlen($s_body_match[0][0]);
                                $final_sub_part_body = substr_replace($sub_part_body, $html_summary_block, $s_offset, 0);
                            } else {
                                $final_sub_part_body = $html_summary_block . $sub_part_body;
                            }
                        } elseif ($sub_part_ct_val === 'text/plain') {
                            $final_sub_part_body = $plain_text_summary_block . $sub_part_body;
                        }
                        $reconstructed_sub_parts[] = $sub_part_leading_ws . $sub_part_headers . ( ($sub_part_headers && $final_sub_part_body) ? "\\r\\n\\r\\n" : "") . $final_sub_part_body;
                    }
                    $modified_part_body = implode($sub_delimiter, $reconstructed_sub_parts);
                }
                $reconstructed_parts[] = $part_leading_whitespace . $part_headers_str . ( ($part_headers_str && $modified_part_body) ? "\\r\\n\\r\\n" : "") . $modified_part_body;
            }
            $new_email_content = implode($delimiter, $reconstructed_parts);

        } elseif ($main_content_type === 'text/html' || (empty($main_content_type) && preg_match("/<html[^>]*>/i", $original_email_content))) {
            // Single part HTML email
            if (preg_match('/<body[^>]*>/i', $original_email_content, $body_tag_match, PREG_OFFSET_CAPTURE)) {
                $offset = $body_tag_match[0][1] + strlen($body_tag_match[0][0]);
                $new_email_content = substr_replace($original_email_content, $html_summary_block, $offset, 0);
            } else {
                $new_email_content = $html_summary_block . $original_email_content;
            }
        } elseif ($main_content_type === 'text/plain' || empty($main_content_type)) {
            // Single part plain text email
            $new_email_content = $plain_text_summary_block . $original_email_content;
        } else {
            // Fallback for other single-part types
            error_log("MTA Hook - Unhandled main Content-Type for summary injection: " . $main_content_type);
            $new_email_content = $plain_text_summary_block . $original_email_content;
        }

        $modifications[] = ['type' => 'replaceContents', 'value' => $new_email_content];
        $summary_status_message = 'Success (summary and translation attempted)';
    } else {
        $summary_status_message = ($summary_text === null) ? 'API Error or Timeout' : 'Empty Summary Received';
    }
} elseif (!$is_outgoing && empty(trim($original_email_content))) {
    $summary_status_message = 'Skipped (Empty Original Content)';
}

// Add headers indicating processing status
$modifications[] = [
    'type' => 'addHeader',
    'name' => 'X-AI-Summary-Status',
    'value' => $summary_status_message
];

if ($enable_translation && isset($translated_content)) {
    $modifications[] = [
        'type' => 'addHeader',
        'name' => 'X-AI-Translation',
        'value' => ($translated_content !== null) ? 'Success' : 'Failed'
    ];
}

// Construct the final response
$response_to_stalwart = [
    'action' => 'accept',
    'response' => [
        'status' => 250,
        'enhancedStatus' => '2.0.0',
        'message' => 'Message accepted, processing: ' . $summary_status_message,
        'disconnect' => false
    ],
    'modifications' => $modifications
];

echo json_encode($response_to_stalwart, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

?>
