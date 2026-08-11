<?php
/**
 * ============================================================
 * WhatsApp CRM - Groq AI Message Generator
 * ============================================================
 * Generates personalized first outreach messages using Groq API
 * Adapts language, tone, and pitch based on lead data
 */

/**
 * Generate personalized outreach message for a lead
 * 
 * @param array $lead Lead data from database
 * @return array ['success' => bool, 'message' => string, 'error' => string|null]
 */
function generateOutreachMessage(array $lead): array {
    // Check if AI messages feature is enabled
    if (!FEATURE_AI_MESSAGES) {
        return getFallbackMessage($lead);
    }

    // Build the prompt
    $prompt = buildGroqPrompt($lead);

    // Call Groq API
    $response = callGroqAPI($prompt);

    if ($response['success']) {
        $message = cleanAIOutput($response['message']);

        // Validate message quality
        if (strlen($message) < 100 || strlen($message) > 1500) {
            logCampaign("AI message length invalid for lead #{$lead['id']}, using fallback", 'WARN');
            return getFallbackMessage($lead);
        }

        return ['success' => true, 'message' => $message, 'error' => null];
    }

    // Fallback if API fails
    logCampaign("Groq API failed for lead #{$lead['id']}: {$response['error']}, using fallback", 'WARN');
    return getFallbackMessage($lead);
}

/**
 * Build structured prompt for Groq based on lead data
 */
function buildGroqPrompt(array $lead): string {
    $businessName = $lead['business_name'];
    $locality = $lead['locality'] ?? '';
    $city = $lead['city'] ?? 'Patna';
    $state = $lead['state'] ?? 'Bihar';
    $rating = $lead['rating'] ?? 'N/A';
    $reviews = $lead['review_count'] ?? 0;
    $websiteStatus = $lead['website_status'] ?? 'no_website';
    $websiteUrl = $lead['website_url'] ?? '';
    $pitchType = $lead['pitch_type'] ?? 'B';
    $language = $lead['language_preference'] ?? 'hinglish';

    // Get relevant services based on pitch type
    $services = getRelevantServices($pitchType);
    $servicesText = implode(', ', $services);

    // Language instruction
    $langInstruction = getLanguageInstruction($language);

    // Pitch angle based on website status
    $pitchAngle = getPitchAngle($pitchType);

    // Build the system prompt
    $systemPrompt = "You are a premium business outreach specialist. You write personalized WhatsApp messages for a digital services freelancer/agency reaching out to local businesses.

RULES (STRICTLY FOLLOW):
1. Write ONLY the message body - no subject line, no greeting prefix like 'Subject:' or 'Message:'
2. Start directly with 'Hi {business name} team,' or similar natural greeting
3. Write 4-5 short paragraphs
4. Sound human, warm, professional - NOT salesy or spammy
5. NEVER mention pricing or cost
6. NEVER use emojis excessively (max 0-1)
7. End with a soft CTA (not pushy)
8. The message should feel handcrafted for this specific business
9. {$langInstruction}
10. DO NOT use generic templates or filler text";

    // Build the user prompt
    $userPrompt = "Generate a personalized first outreach WhatsApp message for this business:

BUSINESS DETAILS:
- Name: {$businessName}
- Area/Locality: {$locality}
- City: {$city}, {$state}
- Google Rating: {$rating}/5
- Total Reviews: {$reviews}
- Website Status: " . ($websiteStatus === 'has_website' ? "Has website ({$websiteUrl})" : "No website") . "

PITCH DIRECTION:
{$pitchAngle}

SERVICES TO MENTION (pick only 2-3 most relevant):
{$servicesText}

MESSAGE STRUCTURE:
1. Local trust/recognition observation (mention area, rating, reviews naturally)
2. Digital observation (what they're missing or could improve)
3. Tailored opportunity + relevant services
4. Soft CTA (offer to share an idea, not hard sell)

IMPORTANT: Make it feel like a real person from their city noticed their business and has a genuine suggestion. Not a mass blast.";

    return json_encode([
        'system' => $systemPrompt,
        'user' => $userPrompt
    ]);
}

/**
 * Call Groq API
 */
function callGroqAPI(string $promptJson): array {
    $prompts = json_decode($promptJson, true);

    $payload = [
        'model' => GROQ_MODEL,
        'messages' => [
            ['role' => 'system', 'content' => $prompts['system']],
            ['role' => 'user', 'content' => $prompts['user']]
        ],
        'max_tokens' => GROQ_MAX_TOKENS,
        'temperature' => GROQ_TEMPERATURE,
        'top_p' => 0.9,
        'stream' => false
    ];

    $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . GROQ_API_KEY
        ]
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return ['success' => false, 'message' => '', 'error' => "cURL error: {$curlError}"];
    }

    $data = json_decode($response, true);

    if ($httpCode !== 200) {
        $error = $data['error']['message'] ?? "HTTP {$httpCode}";
        return ['success' => false, 'message' => '', 'error' => $error];
    }

    $message = $data['choices'][0]['message']['content'] ?? '';

    if (empty($message)) {
        return ['success' => false, 'message' => '', 'error' => 'Empty response from Groq'];
    }

    return ['success' => true, 'message' => $message, 'error' => null];
}

/**
 * Get relevant services based on pitch type
 */
function getRelevantServices(string $pitchType): array {
    if ($pitchType === 'A') {
        // Has website - focus on optimization/upgrade services
        return [
            'AI Agents for customer handling',
            'WhatsApp Automation',
            'Conversion optimization',
            'eCommerce upgrades',
            'Digital Marketing',
            'Custom Web Apps',
            'CRM/workflow automation'
        ];
    }

    // No website - focus on building digital presence
    return [
        'Business Websites',
        'Landing Pages',
        'eCommerce Websites',
        'Digital Marketing',
        'Online presence setup',
        'Customer enquiry systems',
        'Local discoverability'
    ];
}

/**
 * Get language instruction for AI
 */
function getLanguageInstruction(string $language): string {
    switch ($language) {
        case 'hinglish':
            return "Write in natural Hinglish (Hindi words written in Roman/English script mixed with English). Like how people in Bihar/Patna naturally text on WhatsApp. Example tone: 'Aapka Kurji side ka presence dekha', 'kaafi strong brand trust bana hua hai'. Do NOT write in Devanagari script. Keep it Roman script Hinglish.";

        case 'gujarati_english':
            return "Write in a comfortable mix of Gujarati-flavored English. Use occasional Gujarati words/phrases written in Roman script mixed with English. Keep it warm and business-friendly.";

        case 'marathi_english':
            return "Write in a comfortable mix of Marathi-flavored English. Use occasional Marathi words/phrases in Roman script mixed with English. Keep it professional yet conversational.";

        case 'punjabi_english':
            return "Write in a comfortable mix of Punjabi-flavored English. Use occasional Punjabi expressions in Roman script. Keep it energetic and business-friendly.";

        default:
            return "Write in clear, simple English that is easy to read and feels natural for a WhatsApp message. Avoid overly formal or corporate language.";
    }
}

/**
 * Get pitch angle description based on type
 */
function getPitchAngle(string $pitchType): string {
    if ($pitchType === 'A') {
        return "This business HAS a website. Focus on:
- How they can get MORE from their existing digital presence
- Conversion optimization, better enquiry handling
- WhatsApp automation for customer queries
- AI-based customer handling
- eCommerce upgrades or marketing support
- Repeat customer systems
- Don't suggest building a website (they already have one)
- Suggest UPGRADING/OPTIMIZING their digital game";
    }

    return "This business does NOT have a website. Focus on:
- Why they need digital presence in today's market
- How a website/landing page builds trust
- Better enquiry capture and contact flow
- Product/service visibility online
- Local discoverability and search presence
- Mobile-first approach
- How similar businesses in their area benefit from online presence
- Keep it as opportunity, not criticism";
}

/**
 * Clean AI output (remove any unwanted prefixes/formatting)
 */
function cleanAIOutput(string $message): string {
    // Remove common AI prefixes
    $prefixes = [
        '/^(Here\'s|Here is).*?message.*?:\s*/i',
        '/^Subject:.*?\n/i',
        '/^Message:\s*/i',
        '/^---\s*\n/',
        '/^\*\*.*?\*\*\s*\n/'
    ];

    foreach ($prefixes as $pattern) {
        $message = preg_replace($pattern, '', $message);
    }

    // Remove surrounding quotes if present
    $message = trim($message, '"\'');

    // Trim whitespace
    $message = trim($message);

    return $message;
}

/**
 * Fallback message templates (used when Groq API fails)
 */
function getFallbackMessage(array $lead): array {
    $name = $lead['business_name'];
    $locality = $lead['locality'] ?? $lead['city'] ?? 'Patna';
    $rating = $lead['rating'] ?? '';
    $reviews = $lead['review_count'] ?? '';
    $hasWebsite = ($lead['website_status'] === 'has_website');

    if ($hasWebsite) {
        $templates = [
            "Hi {$name} team,\n\nAapka {$locality} area mein business profile dekha — " . ($rating ? "{$rating} rating" : "achhi reputation") . ($reviews ? " aur {$reviews} reviews" : "") . " ke saath kaafi solid trust hai. Website bhi hai, jo achhi baat hai.\n\nMujhe laga ki aap jaise established business ke liye next step website ko aur growth-focused banana ho sakta hai — jaise WhatsApp automation, AI-based customer handling, ya better enquiry conversion.\n\nAaj kal kaafi businesses website hone ke baad bhi proper lead conversion miss kar dete hain. Isi area mein hum kaam karte hain — simple, practical digital upgrades.\n\nAgar aap chahein to main aapke current setup par ek short idea share kar sakta hoon.",

            "Hi {$name} team,\n\n{$locality} mein aapka presence dekha aur Google pe reviews bhi achhe hain. Website already hai aapki — that's great.\n\nBas ek observation share karna chahta tha — aaj kal website ke saath proper automation (WhatsApp auto-reply, enquiry management, digital marketing) lagane se business growth mein real difference aata hai.\n\nHum specifically local businesses ke liye ye systems set up karte hain — AI agents, automation, aur conversion-focused upgrades.\n\nAgar interesting lage to baat kar sakte hain — no pressure."
        ];
    } else {
        $templates = [
            "Hi {$name} team,\n\n{$locality} mein aapka business profile dekha — " . ($rating ? "Google par {$rating} rating" : "achhi local reputation") . ($reviews ? " aur {$reviews} reviews" : "") . " dekhkar laga ki customers ka trust aap par kaafi hai.\n\nBas ek cheez notice hui — abhi dedicated website nahi dikh rahi. Aaj kal jab koi customer search karta hai, to website hone se trust aur enquiries dono better hote hain.\n\nHum local businesses ke liye clean, professional web presence — landing pages, business websites, aur digital marketing set up karte hain. Fully business-focused way mein.\n\nAgar aapko theek lage to main aapke business ke liye ek suitable digital idea share kar sakta hoon.",

            "Hi {$name} team,\n\nAapka {$locality}, {$lead['city']} area mein business dekha. " . ($rating ? "Rating {$rating}" : "Reputation achhi hai") . " — local trust already strong hai.\n\nBas ek suggestion dena chahta tha — aaj ke time mein ek simple website ya landing page hona kaafi help karta hai. Product visibility, customer enquiry capture, aur online trust — sab improve hota hai.\n\nHum specifically local businesses ke liye affordable web presence aur digital marketing solutions provide karte hain.\n\nInterested ho to baat karte hain — koi pressure nahi."
        ];
    }

    // Pick random template
    $message = $templates[array_rand($templates)];

    return ['success' => true, 'message' => $message, 'error' => null];
}
