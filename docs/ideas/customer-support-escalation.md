# Customer Support Escalation System

> Status: Idea
> Created: 2026-03-10
> Context: Post-Phase 1 planning discussion

---

## Concept
Tiered automated support that scales with customer volume while keeping human involvement minimal and reserved for high-value interactions.

## Escalation Tiers

**Tier 0 — FAQ / Knowledge Base**
Static, searchable documentation covering common onboarding and usage questions. No AI, no compute cost. Available 24/7. Handles the predictable 60% — "how do I add a vessel," "where's my TTB report," "how do I reset a password."

**Tier 1 — Slim LLM (Low Cost)**
Lightweight model with VineSuite internal docs as context. Answers contextual questions the FAQ can't — "why is my transfer showing a variance," "what does the 429 rate limit error mean." Escalates to Tier 2 when confidence is low. Cost: fractions of a cent per interaction.

**Tier 2 — Larger LLM (Medium Cost)**
More capable model with access to the customer's recent event log (with permission). Can reason about multi-step workflows and cross-reference domain-specific situations — "I see your last sync failed at 3:47pm, here's what likely happened." Escalates to Tier 3 when the issue requires human judgment. Cost: a few cents per interaction.

**Tier 3 — Human (Email-First)**
Escalation lands in an email queue. Response within business hours. The LLM pre-summarizes the issue and what was already attempted, so Konrad doesn't re-triage from scratch.

**Tier 3+ — Premium Support (Phone)**
Higher-tier customers see a visible phone number at the email hand-off point. Most won't call, but knowing they *could* builds trust disproportionate to the actual support cost.

## Design Principles
- Make the automation transparent. Don't pretend the LLM is a human. Something like: "I'm VineSuite's automated support — I have access to our full documentation. If I can't resolve this, I'll connect you with Konrad directly."
- Escalation threshold should be conservative early on. Better to over-escalate than to have a small model confidently give a wrong answer about production data.
- Tune the threshold down over time as patterns emerge.

## Architecture Compatibility

**Already supported:**
- Event log is append-only and queryable per tenant — Tier 2 LLM can read a customer's recent activity without cross-tenant leakage
- Schema-per-tenant isolation means granting read access to one customer's context carries zero risk of exposing another's data
- API envelope format is consistent, so error responses are parseable and explainable by the LLM
- Internal reference docs (auth-rbac.md, multi-tenancy.md, event-log.md) are already structured for machine consumption

**Would need to be built:**
- Knowledge base / FAQ content (can be generated from existing reference docs as a starting point)
- Support widget or chat interface embedded in the portal
- LLM integration layer with tenant-scoped read access to event logs
- Escalation routing logic (confidence thresholds, topic classification)
- Email queue for Tier 3 hand-offs with LLM-generated issue summaries

## Cost Projection (at ~100 customers)
Assuming ~500 support interactions/month across all tiers:
- Tier 0 (FAQ): $0/month (static hosting)
- Tier 1 (slim LLM): ~$5–10/month
- Tier 2 (larger LLM): ~$20–40/month
- Tier 3 (human time): A handful of emails per week
- Total AI support cost: Under $50/month vs. $2,000+/month for a part-time support hire

## Open Questions
- What percentage of queries will each tier handle in practice? (Need real data to tune.)
- Should Tier 2 be able to suggest fixes and execute them with customer approval, or strictly advisory?
- At what customer count does Tier 3 need to become a hired support person instead of Konrad?
