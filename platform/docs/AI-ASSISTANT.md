# Fleet assistant

## Movement analysis

Open `/movement-analysis` to select an authorized vehicle and a UTC time window of up to 24 hours. Preview retrieves Traccar trips, stops and events and TEVERA's overlapping driver assignment history without contacting OpenAI. Analyze with AI requires explicit consent to send the displayed vehicle details, coordinates/addresses, driver names and report evidence along with the question. No phone numbers, driver email, licence numbers, tracker identifiers or credentials are included automatically.

Evidence is filtered by device and time before use, reduced to an explicit field allowlist, and limited to 50 records per report plus 50 assignments. Out-of-window or malformed records are omitted. Records are labelled T1/S1/E1/D1 for citations. Distance converts meters to km and speed converts knots to km/h. Duration is computed from report timestamps. Displayed totals are partial when limits or boundary filtering omit records. Source reports can themselves omit movements depending on received GPS data and server report configuration.

The model is instructed to state coverage gaps, cite evidence, distinguish assignment from actual driver identity, and avoid inferring idling, speeding or after-hours violations without supporting facts. Model citations and conclusions are not independently validated; users must inspect the evidence. No continuous route trace, automatic anomaly alerts, cross-vehicle ranking or write actions are provided in this version. Each analysis is independent and re-fetches authorized records. Preview works without an API key; a live AI response still requires the configured provider. The count-only assistant below remains available for general setup questions.

The assistant uses the OpenAI Responses API from the PHP backend. Set AI_ENABLED=true, OPENAI_API_KEY and OPENAI_MODEL in the private environment configuration (platform/.env locally or deploy/.env for Docker), then clear/rebuild configuration. Choose a Responses-compatible model available to your API project. No default model or key is assumed. Configure provider spending limits before enabling it. API calls can incur charges.

Fleet managers, company administrators and platform administrators can access /assistant. Existing account, company and subscription checks apply. Every question is independent: there is no shared conversation or conversation retrieval. Users can opt into including four authorized vehicle counts. No vehicle names, identifiers, positions or credentials are automatically sent. Questions themselves are sent to OpenAI, so the UI explains this and discourages sensitive content.

The integration uses store:false, an output-token limit, a timeout, five requests per minute per endpoint/client and a workspace daily allowance of 100 successful requests. It does not use tools, browsing, commands or write access. Answers are escaped text. The database snapshot alongside the assistant is computed locally and is not labelled as an AI insight. It is not a live telematics analysis or predictive maintenance model. No live provider call has been tested without an API key.

Reference: https://developers.openai.com/api/reference/cli/resources/responses/methods/create
