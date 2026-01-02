<?php
declare(strict_types=1);

/**
 * HF Inference via router:
 *   https://router.huggingface.co/hf-inference/models/<model>
 *
 * Sentence Similarity task expects:
 *   { "inputs": { "source_sentence": "...", "sentences": ["...", "..."] } }
 */

function hf_post_json(string $url, string $hfToken, array $payload, int $timeoutSec = 35): array {
    if (strpos($url, "https://api-inference.huggingface.co/models/") === 0) {
        $url = "https://router.huggingface.co/hf-inference/models/" . substr($url, strlen("https://api-inference.huggingface.co/models/"));
    }

    if (!isset($payload["options"]) || !is_array($payload["options"])) {
        $payload["options"] = ["wait_for_model" => true];
    }

    $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($body === false) {
        return ["ok" => false, "http" => 0, "error" => "json_encode_failed", "details" => json_last_error_msg()];
    }

    $ch = curl_init($url);
    if ($ch === false) {
        return ["ok" => false, "http" => 0, "error" => "curl_init_failed", "details" => $url];
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer {$hfToken}",
            "Content-Type: application/json",
            "Accept: application/json",
        ],
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeoutSec,
        CURLOPT_FOLLOWLOCATION => true,
    ]);

    if ($insecure) {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    }

    $raw = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        return ["ok" => false, "http" => $http, "error" => "curl_error", "details" => $err];
    }

    $json = json_decode($raw, true);
    if (!is_array($json)) {
        return ["ok" => false, "http" => $http, "error" => "non_json", "details" => $raw];
    }
    if ($http >= 400) {
        return ["ok" => false, "http" => $http, "error" => "http_error", "details" => $json];
    }
    return ["ok" => true, "http" => $http, "data" => $json];
}

function normalize_kw(string $s): string {
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9\+\#\.\-\s]/i', ' ', $s) ?? $s;
    $s = preg_replace('/\s+/', ' ', $s) ?? $s;
    return trim($s);
}

function fallback_keywords(string $text, int $max = 6): array {
    $text = normalize_kw($text);
    $tokens = preg_split('/\s+/', $text) ?: [];
    $stop = array_fill_keys([
        "with","in","and","for","year","years","yrs","experience","exp","of","a","an","the",
        "plus","minimum","candidate","need","required"
    ], true);

    $out = [];
    foreach ($tokens as $t) {
        if ($t === "" || isset($stop[$t])) continue;
        if (strlen($t) < 2) continue;
        $out[$t] = true;
        if (count($out) >= $max) break;
    }
    return array_keys($out);
}

function is_allowed_short_keyword(string $kw): bool {
    // keep 2-letter medical/degree abbreviations
    $kw = strtolower($kw);
    return in_array($kw, ["md","ms","dm","jr","sr","mb","mbbs","dnb"], true);
}

function filter_keyword(string $kw): ?string {
    $kw = normalize_kw($kw);
    if ($kw === "") return null;
    if (strlen($kw) < 3 && !is_allowed_short_keyword($kw)) return null;
    return $kw;
}

function extract_location_hint(string $text): ?string {
    // "in chennai", "near bangalore" etc
    if (preg_match('/\b(?:in|at|from|near)\s+([a-z]{3,})\b/i', $text, $m)) {
        $w = filter_keyword((string)$m[1]);
        if (!$w) return null;
        $block = ["md","mbbs","dnb","dm","ms","mch","radiology","doctor","fresher","experience","year","years"];
        if (!in_array($w, $block, true)) return $w;
    }
    return null;
}

/**
 * Merge BERT WordPiece tokens into words:
 * - "Ch" + "##ennai" => "Chennai"
 */
function merge_wordpieces(array $tokens): array {
    $out = [];
    $cur = "";
    foreach ($tokens as $t) {
        $t = (string)$t;
        if ($t === "") continue;

        if (strpos($t, "##") === 0) {
            $cur .= substr($t, 2);
            continue;
        }

        if ($cur !== "") $out[] = $cur;
        $cur = $t;
    }
    if ($cur !== "") $out[] = $cur;
    return $out;
}

const A_ROLES = ["doctor","radiologist","physician","technician","nurse","sonographer","fresher","trainee"];
const A_DEGREES = ["md","mbbs","dnb","dm","ms","mch","bds","dr","jr","sr","dgo"];
const A_SPECIALTIES = ["radiology","cardiology","orthopedic","icu","anesthesia","pathology","dermatology"];
const A_STOPWORDS = ["with","in","and","for","year","years","yrs","experience","exp","of","a","an","the"];

/**
 * A: Keyword extraction (rules + NER).
 * Returns up to 6 keywords.
 */
function extract_keywords_A(string $text, string $hfToken): array {
    $textN = trim($text);
    $kws = [];

    foreach (A_ROLES as $role) {
        if (preg_match('/\b' . preg_quote($role, '/') . '\b/i', $textN)) $kws[] = $role;
    }

    // years
    if (preg_match('/(\d+)\s*(\+)?\s*(years?|yrs?)/i', $textN, $m)) {
        $kws[] = normalize_kw($m[1] . " years");
    }

    foreach (A_DEGREES as $deg) {
        if (preg_match('/\b' . preg_quote($deg, '/') . '\b/i', $textN)) $kws[] = $deg;
    }

    foreach (A_SPECIALTIES as $sp) {
        if (preg_match('/\b' . preg_quote($sp, '/') . '\b/i', $textN)) $kws[] = $sp;
    }

    $loc = extract_location_hint($textN);
    if ($loc) $kws[] = $loc;

    // NER (mostly for locations/ORGs)
    $nerUrl = "https://router.huggingface.co/hf-inference/models/dslim/bert-base-NER";
    $ner = hf_post_json($nerUrl, $hfToken, ["inputs" => $textN], 25);

    if ($ner["ok"] && is_array($ner["data"])) {
        $tokens = [];
        foreach ($ner["data"] as $row) {
            if (!is_array($row)) continue;
            $w = $row["word"] ?? "";
            if (!is_string($w) || $w === "") continue;
            $tokens[] = $w;
        }
        foreach (merge_wordpieces($tokens) as $w) {
            $kws[] = $w;
        }
    }

    $stop = array_fill_keys(A_STOPWORDS, true);
    $uniq = [];
    foreach ($kws as $kw) {
        $kw = filter_keyword((string)$kw);
        if (!$kw) continue;
        if (isset($stop[$kw])) continue;
        $uniq[$kw] = true;
    }

    // Priority ordering
    $priority = ["doctor","radiologist","radiology","md","mbbs","dnb","chennai","fresher"];
    $final = [];
    foreach ($priority as $p) if (isset($uniq[$p])) $final[] = $p;

    foreach (array_keys($uniq) as $kw) {
        if (count($final) >= 6) break;
        if (!in_array($kw, $final, true)) $final[] = $kw;
    }

    if (!$final) $final = fallback_keywords($textN, 6);

    return array_slice($final, 0, 6);
}

function extract_name_from_profile(string $profile): string {
    if (preg_match('/^NAME:\s*([^\r\n]+)/mi', $profile, $m)) return trim($m[1]);
    return "";
}

function count_keyword_matches(string $haystack, array $keywords): array {
    $matched = [];
    foreach ($keywords as $kw) {
        $kw = (string)$kw;
        if ($kw !== "" && stripos($haystack, $kw) !== false) $matched[] = $kw;
    }
    return $matched;
}

/**
 * B: Sentence similarity ranking + domain-aware bonus/penalties.
 * Goal: if query contains a specialty (e.g., radiology) and candidate lacks it,
 * rank them lower even if they match location/seniority.
 */
function get_query_must_terms(string $query): array {
    $q = normalize_kw($query);
    $must = ["specialty" => [], "degree" => [], "role" => []];

    foreach (A_SPECIALTIES as $sp) if (preg_match('/\b' . preg_quote($sp, '/') . '\b/i', $q)) $must["specialty"][] = $sp;
    foreach (A_DEGREES as $deg) if (preg_match('/\b' . preg_quote($deg, '/') . '\b/i', $q)) $must["degree"][] = $deg;
    foreach (["doctor","radiologist","technician","nurse"] as $r) if (preg_match('/\b' . preg_quote($r, '/') . '\b/i', $q)) $must["role"][] = $r;

    return $must;
}

function weighted_keyword_bonus(string $profile, array $keywords): float {
    $p = strtolower($profile);
    $bonus = 0.0;

    foreach ($keywords as $kw) {
        $kw = strtolower((string)$kw);
        if ($kw === "" || stripos($p, $kw) === false) continue;

        if (in_array($kw, A_SPECIALTIES, true)) { $bonus += 8.0; continue; }
        if (in_array($kw, A_DEGREES, true)) { $bonus += 6.0; continue; }
        if (preg_match('/\b\d+\s+years\b/', $kw)) { $bonus += 4.0; continue; }
        if (in_array($kw, ["fresher","trainee","jr","sr"], true)) { $bonus += 3.0; continue; }

        // location / generic
        $bonus += 2.0;
    }

    return min(15.0, $bonus);
}

function apply_must_penalty(string $profile, array $must): float {
    $p = strtolower($profile);
    $penalty = 1.0;

    if (!empty($must["specialty"])) {
        $ok = false;
        foreach ($must["specialty"] as $sp) {
            if (stripos($p, $sp) !== false) { $ok = true; break; }
        }
        if (!$ok) $penalty *= 0.6;
    }

    if (!empty($must["degree"])) {
        $ok = false;
        foreach ($must["degree"] as $deg) {
            if (preg_match('/\b' . preg_quote($deg, '/') . '\b/i', $p)) { $ok = true; break; }
        }
        if (!$ok) $penalty *= 0.8;
    }

    if (!empty($must["role"])) {
        $ok = false;
        foreach ($must["role"] as $r) {
            if (preg_match('/\b' . preg_quote($r, '/') . '\b/i', $p)) { $ok = true; break; }
        }
        if (!$ok) $penalty *= 0.9;
    }

    return $penalty;
}

function hf_sentence_similarity_scores(string $source, array $sentences, string $hfToken): array {
    $model = "sentence-transformers/all-MiniLM-L6-v2";
    $url = "https://router.huggingface.co/hf-inference/models/{$model}";

    $clean = [];
    foreach ($sentences as $s) {
        $s = trim((string)$s);
        if ($s === "") continue;
        $clean[] = mb_substr($s, 0, 1200);
    }
    if (!$clean) return ["ok" => false, "model" => $model, "error" => "no_sentences"];

    $res = hf_post_json($url, $hfToken, [
        "inputs" => [
            "source_sentence" => $source,
            "sentences" => $clean,
        ],
    ], 60);

    if (!$res["ok"]) {
        return [
            "ok" => false,
            "model" => $model,
            "error" => (string)($res["error"] ?? "hf_failed"),
            "http" => (int)($res["http"] ?? 0),
            "details" => $res["details"] ?? null,
        ];
    }

    $data = $res["data"];
    if (is_array($data) && isset($data[0]) && is_numeric($data[0])) {
        return ["ok" => true, "model" => $model, "scores" => array_map('floatval', $data)];
    }

    return ["ok" => false, "model" => $model, "error" => "unexpected_shape", "details" => $data];
}

function rank_candidates_B(string $query, array $candidates, array $keywords, string $hfToken, array &$debug = []): array {
    $debug = ["mode" => "semantic", "errors" => [], "model" => "sentence-transformers/all-MiniLM-L6-v2"];

    $sentences = [];
    foreach ($candidates as $c) {
        $profile = (string)($c["profile"] ?? "");
        $sentences[] = mb_substr($profile, 0, 1600);
    }

    $simRes = hf_sentence_similarity_scores($query, $sentences, $hfToken);

    $scores = [];
    if (($simRes["ok"] ?? false) && isset($simRes["scores"])) {
        $scores = $simRes["scores"];
    } else {
        $debug["mode"] = "keyword_only";
        $debug["errors"][] = ["where" => "sentence_similarity", "info" => $simRes];
    }

    $must = get_query_must_terms($query);

    $ranked = [];
    foreach ($candidates as $i => $c) {
        $id = (string)($c["candidate_id"] ?? $c["id"] ?? "");
        $profile = (string)($c["profile"] ?? "");
        $profileClip = mb_substr($profile, 0, 3500);

        $matched = count_keyword_matches($profileClip, $keywords);
        $sim = isset($scores[$i]) ? (float)$scores[$i] : 0.0;

        if ($scores) {
            $base = max(0.0, min(85.0, $sim * 85.0));
            $bonus = weighted_keyword_bonus($profileClip, $keywords); // 0..15
            $penalty = apply_must_penalty($profileClip, $must);       // 0.432..1
            $rating = min(100.0, ($base + $bonus) * $penalty);
        } else {
            $rating = min(100.0, count($matched) * 15.0);
        }

        $ranked[] = [
            "candidate_id" => $id,
            "name" => extract_name_from_profile($profileClip),
            "semantic_score" => round($sim, 6),
            "matched_keywords" => $matched,
            "rating" => round($rating, 2),
        ];
    }

    usort($ranked, function ($a, $b) {
        $ra = isset($a["rating"]) ? (float)$a["rating"] : -1.0;
        $rb = isset($b["rating"]) ? (float)$b["rating"] : -1.0;
        if ($ra === $rb) return 0;
        return ($ra < $rb) ? 1 : -1;
    });

    return $ranked;
}
