# IoT Sensor Integration — Vine-to-Bottle Environmental Monitoring

**Created:** 2026-03-17
**Status:** ⏳ Deferred → Phase 8 (Task TBD), with pre-KMP prep items
**Priority:** High strategic value — fills the widest gap in the 500–15,000 case market
**Estimated Effort:** 3 waves across Phase 8, ~8–12 weeks total
**Source:** `iot-idea-spec.md` (full research document)

---

## Strategic Context

No unified vine-to-bottle IoT platform exists for small wineries today. InnoVint integrates with TankNET and VinWizard for fermentation control but offers zero vineyard sensor ingestion, zero cellar environment monitoring, and zero alerting. A winery running sensors in both vineyard and barrel room needs 3–5 disconnected systems. VineSuite's open ingestion layer — accepting anything speaking MQTT or webhook — fills the widest unserved gap in the target market.

**CO₂ safety monitoring is the killer feature and entry point.** Documented fatalities in wineries from CO₂ accumulation make this both a life-safety feature and a liability shield. Cal/OSHA confined space requirements (Title 8 CCR §§5157–5158) are frequently cited against wineries. Automated monitoring with threshold alerts justifies the entire IoT module subscription on its own.

---

## Architecture Summary

**Data flow:**
```
Sensor → LoRaWAN (915 MHz) → Gateway → ChirpStack v4 (self-hosted)
  → MQTT (Mosquitto) → Laravel subscriber → SensorReadingReceived event
    → Event log (append-only) + TimescaleDB hypertable (query store) + Threshold checker (alerts)
```

**Secondary ingestion** (non-LoRaWAN): HTTP webhook endpoint for vendor APIs (Davis WeatherLink, Tilt Pico, Monnit, iSpindel) normalizes into the same `SensorReadingReceived` pipeline.

### Key Technology Choices

| Component | Choice | Rationale |
|-----------|--------|-----------|
| LoRaWAN network server | **ChirpStack v4** (MIT, Rust) | Self-hosted, same stack (PostgreSQL + Redis + MQTT), no fair-use limits, full data control |
| Time-series storage | **TimescaleDB** (PG extension) | Installs into existing PostgreSQL 16 — not a separate DB. Hypertables, continuous aggregates, 90%+ compression, full SQL/Eloquent compat |
| MQTT broker | **Mosquitto** → graduate to **EMQX** | Mosquitto for <100 devices at launch. EMQX when multi-tenant device isolation matters (native namespace multi-tenancy in v5.9+) |
| Primary protocol | **LoRaWAN** (sub-GHz, 915 MHz US) | Range: km. Battery: years. Penetrates stone/concrete (barrel caves). No viable alternative for vineyard or cellar |

### Data Model

**New event type:** `SensorReadingRecorded` — carries `readings[]` array of metric/value/unit tuples, device context, domain links (`vessel_id`, `lot_id`, `fermentation_round_id` resolved at ingestion), and link quality metadata. Idempotency via DevEUI + frame counter.

**New tables:**
- `device_registry` — hardware serial, type, manufacturer, current vessel/lot assignment, location zone, last seen
- `sensor_readings` — TimescaleDB hypertable (time, device_id, metric, value, unit, quality, vessel_id, lot_id) with 1-day chunk intervals, 7-day compression, 90-day raw retention, indefinite hourly aggregates

**New event source partition:** `iot` (6th partition alongside production, lab, inventory, accounting, compliance)

### Alert System

Real-time threshold checks against Redis-cached rules with anti-fatigue: cooldown periods (default 60 min), sustained-duration requirements (N consecutive readings), hysteresis (alert at X, recover at Y), alert grouping (multiple sensors on same vessel → single notification), escalation tiers (push → SMS → phone call).

Ships with winery-specific presets: tank overheating (>35°C, 15 min), stuck fermentation (<0.5°C delta in 12h), CO₂ dangerous (>5,000 ppm), barrel room too dry (<50% RH), barrel room too warm (>18°C), low battery (<20%), device offline (>2h gap).

---

## Blessed Hardware List (Recommended Defaults)

### Tier 1 — Plug-and-Play

| Product | Type | Protocol | Price |
|---------|------|----------|-------|
| Dragino LHT65N | Temp + humidity | LoRaWAN | ~$35 |
| Dragino LSE01 | Soil moisture + temp + EC | LoRaWAN | ~$60–80 |
| Dragino AQS01-L | CO₂ (NDIR) | LoRaWAN | ~$90–130 |
| Davis Vantage Pro2 GroWeather | Weather station | Davis → WeatherLink API | ~$800–1,100 |
| Tilt Hydrometer | Fermentation SG + temp | BLE → WiFi (Tilt Pico) | ~$135 + $50 bridge |
| Dragino LPS8v2 | Indoor gateway | WiFi/Ethernet | ~$100–140 |
| Dragino DLOS8N | Outdoor gateway | WiFi/Ethernet/4G | ~$200–250 |

**Total barrel room deployment: ~$250–400.** Total vineyard + cellar: ~$500–800 for gateways + $35–130/sensor.

### Tier 2 — Premium Alternatives
Milesight EM500-CO2 ($200–350), SenseCAP S2101/M2, RAK WisGate Edge, Monnit ALTA ecosystem (proprietary but 90K+ customers).

### Tier 3 — DIY
Heltec WiFi LoRa 32 V3 (~$20), iSpindel (~$50–70). Complete DIY node: ~$40–45.

---

## Delivery Waves

**Wave 1 — Barrel Room Monitoring + CO₂ Safety** (highest value, lowest cost)
Ingestion layer (ChirpStack + Mosquitto + webhooks → Laravel → TimescaleDB), device registry, alert system, blessed hardware guide. Market as CO₂ safety with environmental monitoring included. Customer investment: under $300.

**Wave 2 — Fermentation Monitoring**
Tilt/iSpindel HTTP/MQTT integration, readings linked to fermentation rounds and lots, fermentation curve dashboards via continuous aggregates. TankNET and VinWizard API integrations to match and exceed InnoVint.

**Wave 3 — Vineyard Sensors + Weather**
Davis WeatherLink API, Dragino soil moisture, outdoor gateway support. Environmental data linked to vineyard blocks and lots for provenance tracking. Strongest long-term differentiation — no competitor offers integrated vine-to-bottle environmental traceability.

---

## Pricing Recommendation

IoT module add-on: **$50–150/month** on top of base plan. Hardware paths: BYO (API docs, free with subscription), curated starter kits ($500–2,000/zone via Dragino distributors), optional HaaS ($30–50/month per cluster).

Addressable: 3,500–5,000 US wineries × $100–300/month = $4.2–18M/year TAM.

---

## Regulatory Angles

- **TTB:** Sensor data strengthens 5120.17 compliance (automated volume tracking, temperature-correlated evaporation, fermentation completion detection) but doesn't replace existing requirements. 27 CFR 24.300 permits electronic records in any format.
- **Cal/OSHA:** CO₂ monitoring with automated alerts directly addresses Title 8 CCR §§5157–5158 confined space requirements. Average citation: ~$7,000/infraction.
- **FSMA:** Wineries exempt from HARPC but must comply with CGMP (Subpart B). Continuous monitoring is best-practice audit defense.
- **Sustainability certifications:** CCSW, SIP Certified, Napa Green, and LIVE all require overlapping environmental data that IoT automates.

---

## Pre-KMP Prep Items

Lightweight actions that cost nothing now but smooth the full build later:

1. **TimescaleDB in Docker** — Swap `postgres:16` image to `timescale/timescaledb:latest-pg16` in `docker-compose.yml`. The extension is available but unused until Phase 8. Zero behavioral change for existing schemas.
2. **Reserve `iot` event source partition** — Add the partition name to `config/event-sources.php` with an empty event type list. Prevents future naming collisions.
3. **Reserve event types** — Add `SensorReadingRecorded`, `AlertTriggered`, `DeviceRegistered`, `DeviceAssigned`, `DeviceUnassigned` to the event type enum/config as reserved (not implemented). Documents intent for any agent working on Tasks 7–19.

---

## Items Flagged for Validation

- TankNET pricing is custom-quote only — need direct inquiry for partnership terms
- WINEGRID US distribution/support is limited (Portugal-based)
- TTN community gateway coverage in target wine regions should be checked before recommending TTN vs. ChirpStack-only
- EMQX open-source licensing status (Apache 2.0) should be re-verified
- TimescaleDB Apache 2 vs. Community Edition feature split for PG16 (continuous aggregates recently moved to Apache 2)
- 3,500–5,000 winery TAM is estimated from production distribution, not an exact count

---

## Cross-References

- Task 07 (KMP Shared Core): Sync engine may eventually carry sensor alerts to mobile. No action needed now.
- Task 08 (Cellar App): Natural consumer of barrel room alerts via push notifications (FCM).
- Task 17 (Vineyard): Wave 3 vineyard sensors extend this module. `water-sgma-tracking.md` idea shares infrastructure.
- Task 18 (Notifications): Alert escalation tiers (push → SMS → phone) consume the notification automation engine.
- Task 20 (AI Features): Sensor time-series + event log = rich training data for predictive models (stuck fermentation, evaporation forecasting).
- `water-sgma-tracking.md`: Shares soil moisture and flow sensor infrastructure. Should be co-designed.
