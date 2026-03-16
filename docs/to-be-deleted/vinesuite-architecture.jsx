import { useState } from "react";
import {
  Database, Server, Smartphone, Tablet, Globe, Code,
  Wifi, WifiOff, ArrowRight, ArrowDown, ArrowUp,
  Grape, Wine, ShoppingCart, Users, FileText, CreditCard,
  BarChart3, Bell, Search, Mail, Cloud, Layers, Shield,
  ChevronDown, ChevronRight, X, Zap, RefreshCw, Box
} from "lucide-react";

const colors = {
  api: { bg: "#1e1b4b", border: "#6366f1", text: "#c7d2fe", accent: "#818cf8" },
  portal: { bg: "#1a2332", border: "#3b82f6", text: "#bfdbfe", accent: "#60a5fa" },
  cellar: { bg: "#14271a", border: "#22c55e", text: "#bbf7d0", accent: "#4ade80" },
  pos: { bg: "#2a1a0e", border: "#f59e0b", text: "#fef3c7", accent: "#fbbf24" },
  vinebook: { bg: "#1a1625", border: "#a855f7", text: "#e9d5ff", accent: "#c084fc" },
  widgets: { bg: "#1a2528", border: "#14b8a6", text: "#ccfbf1", accent: "#2dd4bf" },
  infra: { bg: "#1c1917", border: "#78716c", text: "#d6d3d1", accent: "#a8a29e" },
  event: { bg: "#2d1520", border: "#f43f5e", text: "#fecdd3", accent: "#fb7185" },
};

const DataFlowLine = ({ x1, y1, x2, y2, color = "#6366f1", animated = true, label }) => (
  <g>
    <line x1={x1} y1={y1} x2={x2} y2={y2} stroke={color} strokeWidth="2" strokeDasharray={animated ? "6 4" : "0"} opacity="0.6">
      {animated && <animate attributeName="stroke-dashoffset" from="20" to="0" dur="1.5s" repeatCount="indefinite" />}
    </line>
    {label && (
      <text x={(x1 + x2) / 2} y={(y1 + y2) / 2 - 6} fill={color} fontSize="9" textAnchor="middle" fontFamily="monospace" opacity="0.8">{label}</text>
    )}
  </g>
);

const PulsingDot = ({ cx, cy, color }) => (
  <g>
    <circle cx={cx} cy={cy} r="4" fill={color}>
      <animate attributeName="r" values="3;6;3" dur="2s" repeatCount="indefinite" />
      <animate attributeName="opacity" values="1;0.3;1" dur="2s" repeatCount="indefinite" />
    </circle>
  </g>
);

const SystemCard = ({ title, icon: Icon, color, children, badge, onClick, expanded, x, y, w = 200, h = 80 }) => (
  <g onClick={onClick} style={{ cursor: onClick ? "pointer" : "default" }}>
    <rect x={x} y={y} width={w} height={h} rx="12" fill={color.bg} stroke={color.border} strokeWidth="2" opacity="0.95" />
    <rect x={x} y={y} width={w} height={h} rx="12" fill="url(#cardSheen)" opacity="0.08" />
    {badge && (
      <g>
        <rect x={x + w - 56} y={y + 6} width={50} height={18} rx="9" fill={color.border} opacity="0.25" />
        <text x={x + w - 31} y={y + 18} fill={color.accent} fontSize="8" textAnchor="middle" fontWeight="bold" fontFamily="monospace">{badge}</text>
      </g>
    )}
    <text x={x + 14} y={y + 26} fill={color.accent} fontSize="13" fontWeight="bold" fontFamily="system-ui, sans-serif">{title}</text>
    {children}
  </g>
);

const DetailPanel = ({ title, color, items, onClose }) => (
  <div className="fixed inset-0 z-50 flex items-center justify-center" style={{ background: "rgba(0,0,0,0.7)", backdropFilter: "blur(4px)" }}>
    <div className="relative rounded-2xl p-6 max-w-lg w-full mx-4 shadow-2xl" style={{ background: color.bg, border: `2px solid ${color.border}` }}>
      <button onClick={onClose} className="absolute top-3 right-3 p-1 rounded-full hover:bg-white/10 transition">
        <X size={18} color={color.text} />
      </button>
      <h2 className="text-xl font-bold mb-4" style={{ color: color.accent }}>{title}</h2>
      <div className="space-y-3">
        {items.map((item, i) => (
          <div key={i} className="rounded-lg p-3" style={{ background: "rgba(255,255,255,0.05)" }}>
            <div className="flex items-center gap-2 mb-1">
              <div className="w-2 h-2 rounded-full" style={{ background: color.accent }} />
              <span className="text-sm font-semibold" style={{ color: color.text }}>{item.label}</span>
            </div>
            <p className="text-xs ml-4 leading-relaxed" style={{ color: color.text, opacity: 0.7 }}>{item.detail}</p>
          </div>
        ))}
      </div>
    </div>
  </div>
);

const models = [
  "Lot", "Vessel", "Barrel", "WorkOrder", "Addition", "Transfer",
  "PressLog", "FilterLog", "BlendTrial", "WineryProfile", "Event", "User"
];

const eventTypes = [
  "lot_created", "lot_split", "addition_made", "transfer_executed",
  "barrel_filled", "pressing_logged", "bottling_completed",
  "order_placed", "club_charge_processed", "ttb_report_generated"
];

const panelData = {
  api: {
    title: "Platform API — The Brain",
    items: [
      { label: "Laravel 12 + PHP 8.4", detail: "REST JSON API with versioned endpoints (/api/v1/). Consistent envelope: { data, meta, errors }." },
      { label: "Multi-Tenancy (stancl/tenancy)", detail: "Schema-per-tenant via PostgreSQL schemas. Central schema holds tenant registry, billing, VineBook data. Tenant ID via subdomain or header." },
      { label: "Auth: Laravel Sanctum", detail: "Bearer token auth for all clients. Scoped tokens per client type (portal, cellar, POS, widget). Rate limiting per token." },
      { label: "Background Jobs (Horizon)", detail: "Queues: critical (payments, auth), default (orders, notifications), low (reports, AI, sync). Powered by Redis." },
      { label: "10 API Controllers", detail: "Lot, Vessel, Barrel, WorkOrder, Addition, Transfer, PressLog, FilterLog, EventSync, WineryProfile — plus Auth, Billing, Team." },
      { label: "18 Eloquent Models", detail: models.join(", ") + " — CRUD tables are materialized views of the event stream." },
    ]
  },
  portal: {
    title: "Management Portal",
    items: [
      { label: "TALL Stack", detail: "Tailwind + Alpine.js + Livewire + Laravel. Server-rendered with Filament v3 for admin scaffolding." },
      { label: "Real-time via Reverb", detail: "Work order completions, POS sales, inventory updates, club processing progress — all push live via WebSockets." },
      { label: "Full Back-of-House", detail: "Production management, vineyard, inventory, COGS, TTB compliance, club processing, CRM, reservations, wholesale, reporting." },
      { label: "Custom Livewire Components", detail: "Visual tank map, fermentation charts, TTB report review, club processing flow — beyond Filament's standard tables." },
    ]
  },
  cellar: {
    title: "Cellar App (Offline-First)",
    items: [
      { label: "Kotlin Multiplatform (KMP)", detail: "Shared core (sync engine, SQLite, Ktor API client, business logic) + native UI: SwiftUI (iOS) and Jetpack Compose (Android)." },
      { label: "Offline Sync via Event Queue", detail: "Operations write to local SQLite → queue in outbox → POST /events/sync when online. Idempotency keys prevent duplicates." },
      { label: "Conflict Resolution", detail: "Last-write-wins by performed_at. Volume operations validate physical constraints. Clock drift mitigated via NTP checks." },
      { label: "Scope", detail: "Work orders, additions, transfers, barrel ops (QR scan), lab analysis, fermentation data, lot history, daily schedule." },
    ]
  },
  pos: {
    title: "POS App (Offline-First)",
    items: [
      { label: "Shared KMP Core with Cellar", detail: "Same sync engine, SQLite layer, and API client. Native tablet UI: SwiftUI (iPad) + Jetpack Compose (Android tablet)." },
      { label: "Stripe Terminal (Native SDK)", detail: "Card-present payments with offline queuing in reader hardware. Up to ~$5K offline transactions per reader." },
      { label: "Full Offline Commerce", detail: "Product catalog cached in SQLite, cash payments logged locally, club signups queued, inventory optimistically deducted." },
      { label: "Scope", detail: "Cart, tabs, split payments, club signup, reservation check-in, tasting fee waivers, member discounts, shift management." },
    ]
  },
  vinebook: {
    title: "VineBook Directory",
    items: [
      { label: "Astro Static Site", detail: "Hosted on Cloudflare Pages. Static shell for 11,000 winery pages from TTB data, enriched with Yelp + Google Places." },
      { label: "Island Architecture", detail: "Subscriber pages get hydrated React islands: ShopWidget, BookingWidget, ClubSignupWidget, MemberPortalWidget — all hit the Laravel API." },
      { label: "SEO Play", detail: "One page per winery targeting branded search. Regional landing pages (Paso Robles, Napa, Sonoma). Variety pages." },
      { label: "Claim Flow", detail: "Winery owner claims stub → verifies via TTB permit or business email → enhanced free profile → upsell to suite." },
    ]
  },
  widgets: {
    title: "Embeddable Widgets",
    items: [
      { label: "JS Widgets", detail: "Drop-in scripts for a winery's existing website. Shop, booking, club signup — all powered by the platform API." },
      { label: "Always Online", detail: "No offline capability needed — these run on the winery's public website for consumers." },
      { label: "Shared API Surface", detail: "Same public API endpoints that VineBook islands consume. /api/v1/public/wineries/{slug}/*" },
    ]
  },
  events: {
    title: "Event Log — Core Data Pattern",
    items: [
      { label: "Append-Only Log", detail: "All winery operations recorded as immutable events. CRUD tables are materialized views kept in sync by event handlers." },
      { label: "Why Events?", detail: "TTB reporting = simple aggregation query. Offline sync is safe. Full audit trail is free. Undo via correcting events." },
      { label: "Schema", detail: "id (UUID), entity_type, entity_id, operation_type, payload (JSONB), performed_by, performed_at, synced_at, device_id, idempotency_key." },
      { label: "Event Types", detail: eventTypes.join(", ") + " — spanning vineyard, cellar, inventory, sales, and compliance domains." },
    ]
  },
  infra: {
    title: "Infrastructure Stack",
    items: [
      { label: "PostgreSQL 16", detail: "Primary data store. Schema-per-tenant isolation. Central schema for platform-wide data." },
      { label: "Redis 7", detail: "Cache + queue backend. Powers Laravel Horizon job processing and application caching." },
      { label: "Meilisearch", detail: "Full-text search for lots, customers, SKUs. Self-hosted, fast indexing." },
      { label: "Cloudflare R2", detail: "S3-compatible file storage for winery assets. Cheaper than AWS S3." },
      { label: "Mailpit (Dev) / Resend (Prod)", detail: "Email testing locally, Resend for transactional email in production. Twilio for SMS." },
      { label: "Docker Compose", detail: "Full local dev: app, postgres, redis, meilisearch, mailpit, horizon. Test DB on tmpfs for speed." },
    ]
  },
};

export default function VineSuiteArchitecture() {
  const [activePanel, setActivePanel] = useState(null);
  const [viewMode, setViewMode] = useState("architecture");
  const [hoveredNode, setHoveredNode] = useState(null);

  const togglePanel = (key) => setActivePanel(activePanel === key ? null : key);

  return (
    <div className="min-h-screen bg-gray-950 text-white p-4">
      <div className="max-w-6xl mx-auto">
        {/* Header */}
        <div className="text-center mb-6">
          <div className="flex items-center justify-center gap-3 mb-2">
            <Grape size={28} className="text-purple-400" />
            <h1 className="text-3xl font-bold bg-gradient-to-r from-indigo-400 via-purple-400 to-pink-400 bg-clip-text text-transparent">
              VineSuite Architecture
            </h1>
            <Wine size={28} className="text-pink-400" />
          </div>
          <p className="text-gray-500 text-sm">Winery SaaS Platform — Click any component to explore</p>
        </div>

        {/* View Toggle */}
        <div className="flex justify-center gap-2 mb-6">
          {[
            { id: "architecture", label: "System Overview", icon: Layers },
            { id: "dataflow", label: "Data Flow", icon: RefreshCw },
            { id: "models", label: "Domain Models", icon: Box },
          ].map(({ id, label, icon: Ico }) => (
            <button
              key={id}
              onClick={() => setViewMode(id)}
              className={`flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium transition-all ${
                viewMode === id
                  ? "bg-indigo-600 text-white shadow-lg shadow-indigo-600/30"
                  : "bg-gray-800 text-gray-400 hover:bg-gray-700 hover:text-gray-200"
              }`}
            >
              <Ico size={14} />
              {label}
            </button>
          ))}
        </div>

        {/* ═══════ Architecture View ═══════ */}
        {viewMode === "architecture" && (
          <div className="space-y-4">
            {/* Client Layer */}
            <div className="rounded-xl border border-gray-800 p-4" style={{ background: "rgba(255,255,255,0.02)" }}>
              <div className="flex items-center gap-2 mb-3">
                <Globe size={14} className="text-gray-500" />
                <span className="text-xs font-mono text-gray-500 uppercase tracking-wider">Client Layer — User-Facing Surfaces</span>
              </div>
              <div className="grid grid-cols-2 md:grid-cols-5 gap-3">
                {[
                  { key: "portal", icon: BarChart3, title: "Management Portal", tech: "TALL + Filament", badge: "WEB", desc: "Owner / Winemaker / Accountant", connectivity: "Online" },
                  { key: "cellar", icon: Smartphone, title: "Cellar App", tech: "KMP + Native UI", badge: "MOBILE", desc: "Cellar Hand / Winemaker", connectivity: "Offline-first" },
                  { key: "pos", icon: CreditCard, title: "POS App", tech: "KMP + Stripe Terminal", badge: "TABLET", desc: "Tasting Room Staff", connectivity: "Offline-first" },
                  { key: "vinebook", icon: Globe, title: "VineBook", tech: "Astro + Cloudflare", badge: "STATIC", desc: "Wine Consumers", connectivity: "Online" },
                  { key: "widgets", icon: Code, title: "Widgets", tech: "JS Embeds", badge: "EMBED", desc: "Website Visitors", connectivity: "Online" },
                ].map(({ key, icon: Ico, title, tech, badge, desc, connectivity }) => (
                  <button
                    key={key}
                    onClick={() => togglePanel(key)}
                    onMouseEnter={() => setHoveredNode(key)}
                    onMouseLeave={() => setHoveredNode(null)}
                    className="rounded-xl p-4 text-left transition-all hover:scale-[1.03] hover:shadow-lg"
                    style={{
                      background: colors[key].bg,
                      border: `1.5px solid ${hoveredNode === key ? colors[key].accent : colors[key].border}`,
                      boxShadow: hoveredNode === key ? `0 0 20px ${colors[key].border}40` : "none",
                    }}
                  >
                    <div className="flex items-center justify-between mb-2">
                      <Ico size={18} style={{ color: colors[key].accent }} />
                      <span className="text-[10px] font-mono px-2 py-0.5 rounded-full" style={{ background: `${colors[key].border}30`, color: colors[key].accent }}>{badge}</span>
                    </div>
                    <div className="text-sm font-bold mb-1" style={{ color: colors[key].accent }}>{title}</div>
                    <div className="text-[10px] font-mono mb-2" style={{ color: colors[key].text, opacity: 0.6 }}>{tech}</div>
                    <div className="text-[10px]" style={{ color: colors[key].text, opacity: 0.5 }}>{desc}</div>
                    <div className="flex items-center gap-1 mt-2">
                      {connectivity === "Offline-first" ? <WifiOff size={10} className="text-yellow-500" /> : <Wifi size={10} className="text-green-500" />}
                      <span className="text-[9px]" style={{ color: connectivity === "Offline-first" ? "#eab308" : "#22c55e" }}>{connectivity}</span>
                    </div>
                  </button>
                ))}
              </div>
            </div>

            {/* Connection Arrows */}
            <div className="flex justify-center">
              <div className="flex items-center gap-3 text-gray-600">
                <div className="h-px w-16 bg-gradient-to-r from-transparent via-gray-600 to-transparent" />
                <div className="flex items-center gap-1.5 text-[10px] font-mono">
                  <ArrowDown size={12} />
                  <span>REST JSON API / WebSocket</span>
                  <ArrowUp size={12} />
                </div>
                <div className="h-px w-16 bg-gradient-to-r from-transparent via-gray-600 to-transparent" />
              </div>
            </div>

            {/* API + Event Log Layer */}
            <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
              {/* API */}
              <button
                onClick={() => togglePanel("api")}
                onMouseEnter={() => setHoveredNode("api")}
                onMouseLeave={() => setHoveredNode(null)}
                className="md:col-span-2 rounded-xl p-5 text-left transition-all hover:shadow-lg"
                style={{
                  background: colors.api.bg,
                  border: `2px solid ${hoveredNode === "api" ? colors.api.accent : colors.api.border}`,
                  boxShadow: hoveredNode === "api" ? `0 0 30px ${colors.api.border}40` : "none",
                }}
              >
                <div className="flex items-center gap-3 mb-3">
                  <Server size={22} style={{ color: colors.api.accent }} />
                  <div>
                    <div className="text-lg font-bold" style={{ color: colors.api.accent }}>Platform API</div>
                    <div className="text-[10px] font-mono" style={{ color: colors.api.text, opacity: 0.5 }}>Laravel 12 · PHP 8.4 · Sanctum Auth</div>
                  </div>
                  <span className="ml-auto text-[10px] font-mono px-3 py-1 rounded-full" style={{ background: `${colors.api.border}25`, color: colors.api.accent }}>THE BRAIN</span>
                </div>
                <div className="grid grid-cols-2 md:grid-cols-4 gap-2">
                  {[
                    { icon: Shield, label: "Auth + RBAC", sub: "5 roles" },
                    { icon: Layers, label: "Multi-Tenant", sub: "Schema isolation" },
                    { icon: Zap, label: "Horizon Jobs", sub: "3 priority queues" },
                    { icon: Search, label: "Meilisearch", sub: "Full-text" },
                    { icon: FileText, label: "10 Controllers", sub: "/api/v1/*" },
                    { icon: Database, label: "18 Models", sub: "Eloquent ORM" },
                    { icon: Bell, label: "Reverb WS", sub: "Real-time push" },
                    { icon: Mail, label: "Notifications", sub: "Email + SMS" },
                  ].map(({ icon: Ico, label, sub }, i) => (
                    <div key={i} className="rounded-lg p-2 flex items-center gap-2" style={{ background: "rgba(255,255,255,0.04)" }}>
                      <Ico size={13} style={{ color: colors.api.accent }} />
                      <div>
                        <div className="text-[10px] font-semibold" style={{ color: colors.api.text }}>{label}</div>
                        <div className="text-[9px]" style={{ color: colors.api.text, opacity: 0.4 }}>{sub}</div>
                      </div>
                    </div>
                  ))}
                </div>
              </button>

              {/* Event Log */}
              <button
                onClick={() => togglePanel("events")}
                onMouseEnter={() => setHoveredNode("events")}
                onMouseLeave={() => setHoveredNode(null)}
                className="rounded-xl p-5 text-left transition-all hover:shadow-lg"
                style={{
                  background: colors.event.bg,
                  border: `2px solid ${hoveredNode === "events" ? colors.event.accent : colors.event.border}`,
                  boxShadow: hoveredNode === "events" ? `0 0 30px ${colors.event.border}40` : "none",
                }}
              >
                <div className="flex items-center gap-2 mb-3">
                  <FileText size={20} style={{ color: colors.event.accent }} />
                  <div>
                    <div className="text-base font-bold" style={{ color: colors.event.accent }}>Event Log</div>
                    <div className="text-[10px] font-mono" style={{ color: colors.event.text, opacity: 0.5 }}>Append-only · Immutable</div>
                  </div>
                </div>
                <div className="space-y-1.5">
                  {["lot_created", "transfer_executed", "addition_made", "order_placed", "club_charge_processed"].map((evt, i) => (
                    <div key={i} className="flex items-center gap-2 rounded px-2 py-1" style={{ background: "rgba(255,255,255,0.04)" }}>
                      <div className="w-1.5 h-1.5 rounded-full" style={{ background: colors.event.accent }} />
                      <span className="text-[10px] font-mono" style={{ color: colors.event.text, opacity: 0.7 }}>{evt}</span>
                    </div>
                  ))}
                  <div className="text-[9px] text-center mt-1" style={{ color: colors.event.text, opacity: 0.3 }}>+ 5 more event domains</div>
                </div>
              </button>
            </div>

            {/* Connection Arrows */}
            <div className="flex justify-center">
              <div className="flex items-center gap-3 text-gray-600">
                <div className="h-px w-16 bg-gradient-to-r from-transparent via-gray-600 to-transparent" />
                <div className="flex items-center gap-1.5 text-[10px] font-mono">
                  <ArrowDown size={12} />
                  <span>Reads / Writes</span>
                  <ArrowDown size={12} />
                </div>
                <div className="h-px w-16 bg-gradient-to-r from-transparent via-gray-600 to-transparent" />
              </div>
            </div>

            {/* Infrastructure Layer */}
            <button
              onClick={() => togglePanel("infra")}
              onMouseEnter={() => setHoveredNode("infra")}
              onMouseLeave={() => setHoveredNode(null)}
              className="w-full rounded-xl p-4 text-left transition-all hover:shadow-lg"
              style={{
                background: colors.infra.bg,
                border: `1.5px solid ${hoveredNode === "infra" ? colors.infra.accent : colors.infra.border}`,
                boxShadow: hoveredNode === "infra" ? `0 0 20px ${colors.infra.border}40` : "none",
              }}
            >
              <div className="flex items-center gap-2 mb-3">
                <Cloud size={14} style={{ color: colors.infra.accent }} />
                <span className="text-xs font-mono uppercase tracking-wider" style={{ color: colors.infra.text, opacity: 0.5 }}>Infrastructure</span>
              </div>
              <div className="grid grid-cols-2 md:grid-cols-6 gap-2">
                {[
                  { icon: Database, label: "PostgreSQL 16", sub: "Schema-per-tenant" },
                  { icon: Zap, label: "Redis 7", sub: "Cache + Queues" },
                  { icon: Search, label: "Meilisearch", sub: "Full-text search" },
                  { icon: Cloud, label: "Cloudflare R2", sub: "File storage" },
                  { icon: Mail, label: "Resend / Twilio", sub: "Email + SMS" },
                  { icon: CreditCard, label: "Stripe", sub: "Payments + Terminal" },
                ].map(({ icon: Ico, label, sub }, i) => (
                  <div key={i} className="rounded-lg p-3 flex flex-col items-center text-center" style={{ background: "rgba(255,255,255,0.03)" }}>
                    <Ico size={18} style={{ color: colors.infra.accent }} />
                    <div className="text-[10px] font-semibold mt-1" style={{ color: colors.infra.text }}>{label}</div>
                    <div className="text-[9px]" style={{ color: colors.infra.text, opacity: 0.4 }}>{sub}</div>
                  </div>
                ))}
              </div>
            </button>
          </div>
        )}

        {/* ═══════ Data Flow View ═══════ */}
        {viewMode === "dataflow" && (
          <div className="space-y-4">
            {[
              {
                title: "Offline Sync Flow (Cellar & POS)",
                color: colors.cellar,
                steps: [
                  { icon: Smartphone, label: "User Action", detail: "Cellar hand logs addition or POS processes sale on device", color: "#4ade80" },
                  { icon: Database, label: "Local SQLite", detail: "Write to local SQLite immediately — UI updates instantly, no lag", color: "#4ade80" },
                  { icon: FileText, label: "Outbox Queue", detail: "Event queued in local outbox table with idempotency_key (UUID)", color: "#fbbf24" },
                  { icon: Wifi, label: "Connectivity Check", detail: "Background sync checks network every 5min. Immediate sync when online", color: "#60a5fa" },
                  { icon: Server, label: "POST /events/sync", detail: "Batch POST events to API. Server deduplicates via idempotency_key", color: "#818cf8" },
                  { icon: Database, label: "Event Log → Models", detail: "Server appends to event log, updates materialized CRUD tables, confirms receipt", color: "#f43f5e" },
                  { icon: RefreshCw, label: "Pull Latest State", detail: "Device pulls latest state from server. Local cache refreshed", color: "#2dd4bf" },
                ]
              },
              {
                title: "Real-Time Portal Updates",
                color: colors.portal,
                steps: [
                  { icon: Smartphone, label: "Cellar/POS Event", detail: "Mobile app syncs event (work order complete, sale recorded)", color: "#4ade80" },
                  { icon: Server, label: "API Processes", detail: "Laravel processes event, updates models, triggers event handlers", color: "#818cf8" },
                  { icon: Zap, label: "Reverb Broadcast", detail: "Event broadcast via Laravel Reverb WebSocket server", color: "#fbbf24" },
                  { icon: BarChart3, label: "Portal Updates", detail: "Livewire components receive event — dashboard, inventory, charts update live", color: "#60a5fa" },
                ]
              },
              {
                title: "Payment Flow (POS → Stripe)",
                color: colors.pos,
                steps: [
                  { icon: ShoppingCart, label: "Cart Built", detail: "Staff builds cart from cached product catalog on tablet", color: "#fbbf24" },
                  { icon: CreditCard, label: "Stripe Terminal", detail: "Card presented to reader. Native SDK handles auth (works offline!)", color: "#f59e0b" },
                  { icon: WifiOff, label: "Offline Queue", detail: "If offline: reader hardware queues payment (~$5K limit). If online: instant capture", color: "#f43f5e" },
                  { icon: Wifi, label: "Sync on Reconnect", detail: "Queued payments auto-capture when connectivity returns", color: "#4ade80" },
                  { icon: Server, label: "Server Reconcile", detail: "API records sale event, deducts inventory, updates club member status", color: "#818cf8" },
                ]
              },
              {
                title: "VineBook Page Build",
                color: colors.vinebook,
                steps: [
                  { icon: Database, label: "TTB Seed Data", detail: "11,000 US bonded wineries imported from TTB public permit database", color: "#a855f7" },
                  { icon: Globe, label: "API Enrichment", detail: "Yelp Fusion + Google Places + Wine-Searcher. Staggered refresh: ~370 wineries/day", color: "#c084fc" },
                  { icon: FileText, label: "Astro Build", detail: "Static HTML generated at build time. Nightly rebuilds for fresh data", color: "#818cf8" },
                  { icon: Cloud, label: "Cloudflare CDN", detail: "Pages deployed to global CDN. 24hr cache TTL", color: "#60a5fa" },
                  { icon: Code, label: "Islands Hydrate", detail: "Subscriber pages get React islands (Shop, Booking, Club) hitting Laravel API at runtime", color: "#2dd4bf" },
                ]
              },
            ].map((flow, fi) => (
              <div key={fi} className="rounded-xl border p-5" style={{ background: `${flow.color.bg}`, borderColor: `${flow.color.border}40` }}>
                <h3 className="text-sm font-bold mb-4" style={{ color: flow.color.accent }}>{flow.title}</h3>
                <div className="flex flex-wrap items-start gap-1">
                  {flow.steps.map((step, si) => (
                    <div key={si} className="flex items-start">
                      <div className="flex flex-col items-center" style={{ width: "120px" }}>
                        <div className="w-10 h-10 rounded-full flex items-center justify-center mb-2" style={{ background: `${step.color}15`, border: `1.5px solid ${step.color}50` }}>
                          <step.icon size={16} style={{ color: step.color }} />
                        </div>
                        <div className="text-[10px] font-bold text-center mb-1" style={{ color: step.color }}>{step.label}</div>
                        <div className="text-[9px] text-center leading-tight px-1" style={{ color: flow.color.text, opacity: 0.6 }}>{step.detail}</div>
                      </div>
                      {si < flow.steps.length - 1 && (
                        <div className="flex items-center mt-4">
                          <ArrowRight size={14} style={{ color: flow.color.accent, opacity: 0.3 }} />
                        </div>
                      )}
                    </div>
                  ))}
                </div>
              </div>
            ))}
          </div>
        )}

        {/* ═══════ Domain Models View ═══════ */}
        {viewMode === "models" && (
          <div className="space-y-4">
            {[
              {
                domain: "Production",
                color: colors.cellar,
                entities: [
                  { name: "Lot", fields: "UUID, code, vintage, varietal, appellation, status, current_volume_gallons", relations: "has many Events, Additions, Transfers. Belongs to Vessel" },
                  { name: "Vessel", fields: "UUID, name, type (tank|barrel|bin), capacity_gallons, current_volume", relations: "has many Lots. Has many Transfers (in/out)" },
                  { name: "Barrel", fields: "UUID, code, cooper, forest, toast_level, volume_gallons, vintage_acquired", relations: "is a type of Vessel. Has QR code for scanning" },
                  { name: "WorkOrder", fields: "UUID, title, type, status (pending|in_progress|completed), assigned_to, due_date", relations: "belongs to User. May reference Lot, Vessel. Has template" },
                  { name: "Addition", fields: "UUID, lot_id, substance, quantity, unit, performed_at", relations: "belongs to Lot. Creates Event. SO2 tracked cumulatively" },
                  { name: "Transfer", fields: "UUID, from_vessel, to_vessel, lot_id, volume_gallons, performed_at", relations: "belongs to source/dest Vessel and Lot. Creates Event" },
                ]
              },
              {
                domain: "Processing",
                color: colors.event,
                entities: [
                  { name: "PressLog", fields: "UUID, lot_id, press_type, input_tons, output_gallons, press_fractions", relations: "belongs to Lot. Creates Event" },
                  { name: "FilterLog", fields: "UUID, lot_id, filter_type, pre_volume, post_volume, performed_at", relations: "belongs to Lot. Creates Event" },
                  { name: "BlendTrial", fields: "UUID, name, status, target_volume, notes", relations: "has many BlendTrialComponents (lot + percentage)" },
                  { name: "BlendTrialComponent", fields: "UUID, blend_trial_id, lot_id, percentage", relations: "belongs to BlendTrial and Lot" },
                ]
              },
              {
                domain: "Platform",
                color: colors.api,
                entities: [
                  { name: "Tenant", fields: "UUID, name, slug, domain, plan, trial_ends_at", relations: "Central schema. Owns a PostgreSQL schema. Has billing via Cashier" },
                  { name: "User", fields: "UUID, name, email, role (owner|admin|winemaker|cellar_hand|sales), is_active", relations: "belongs to Tenant schema. Has Sanctum tokens. Has Spatie permissions" },
                  { name: "CentralUser", fields: "UUID, name, email, global_id", relations: "Central schema. Maps to per-tenant User records" },
                  { name: "Event", fields: "UUID, entity_type, entity_id, operation_type, payload (JSONB), performed_at, idempotency_key", relations: "The core pattern. Every operation appends here. Materialized into CRUD tables" },
                  { name: "WineryProfile", fields: "UUID, winery_name, region, appellation, address, phone, website, logo_path", relations: "belongs to Tenant. Surfaced in VineBook directory" },
                ]
              },
            ].map((domain, di) => (
              <div key={di} className="rounded-xl border p-5" style={{ background: domain.color.bg, borderColor: `${domain.color.border}40` }}>
                <h3 className="text-sm font-bold mb-3" style={{ color: domain.color.accent }}>{domain.domain} Domain</h3>
                <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
                  {domain.entities.map((entity, ei) => (
                    <div key={ei} className="rounded-lg p-3" style={{ background: "rgba(255,255,255,0.04)", border: `1px solid ${domain.color.border}20` }}>
                      <div className="flex items-center gap-2 mb-2">
                        <Database size={12} style={{ color: domain.color.accent }} />
                        <span className="text-xs font-bold font-mono" style={{ color: domain.color.accent }}>{entity.name}</span>
                      </div>
                      <div className="text-[10px] font-mono mb-2 leading-relaxed" style={{ color: domain.color.text, opacity: 0.5 }}>{entity.fields}</div>
                      <div className="text-[10px] leading-relaxed" style={{ color: domain.color.text, opacity: 0.7 }}>{entity.relations}</div>
                    </div>
                  ))}
                </div>
              </div>
            ))}

            {/* Relationships diagram */}
            <div className="rounded-xl border border-gray-800 p-5" style={{ background: "rgba(255,255,255,0.02)" }}>
              <h3 className="text-sm font-bold text-gray-400 mb-3">Key Relationships</h3>
              <div className="flex flex-wrap gap-2">
                {[
                  "Tenant ─┬─ Users", "       ├─ Lots ──── Events", "       ├─ Vessels ── Transfers",
                  "       ├─ Barrels ─ (QR scans)", "       ├─ WorkOrders", "       └─ WineryProfile ── VineBook",
                ].map((line, i) => (
                  <div key={i} className="w-full">
                    <code className="text-[11px] text-indigo-300 font-mono">{line}</code>
                  </div>
                ))}
              </div>
            </div>
          </div>
        )}

        {/* Detail Panels */}
        {activePanel && panelData[activePanel] && (
          <DetailPanel
            title={panelData[activePanel].title}
            color={colors[activePanel] || colors.api}
            items={panelData[activePanel].items}
            onClose={() => setActivePanel(null)}
          />
        )}

        {/* Legend */}
        <div className="mt-6 flex flex-wrap items-center justify-center gap-4 text-[10px] text-gray-600">
          <div className="flex items-center gap-1"><div className="w-2 h-2 rounded-full bg-green-500" /> Offline-first</div>
          <div className="flex items-center gap-1"><div className="w-2 h-2 rounded-full bg-blue-500" /> Online only</div>
          <div className="flex items-center gap-1"><div className="w-2 h-2 rounded-full bg-rose-500" /> Event-sourced</div>
          <div className="flex items-center gap-1"><div className="w-2 h-2 rounded-full bg-indigo-500" /> API-powered</div>
          <span className="text-gray-700">|</span>
          <span>Click any component for details</span>
        </div>
      </div>
    </div>
  );
}
