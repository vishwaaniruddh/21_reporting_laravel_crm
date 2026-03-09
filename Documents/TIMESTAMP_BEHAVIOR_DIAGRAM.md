# Timestamp Behavior Diagram

## Scenario 1: New Alert (First Insert)

```
┌─────────────────────────────────────────────────────────────────┐
│ MySQL (Source)                                                  │
├─────────────────────────────────────────────────────────────────┤
│ Alert ID: 12345                                                 │
│ createtime:    2026-03-04 10:00:00                             │
│ receivedtime:  2026-03-04 10:00:05                             │
│ closedtime:    NULL                                             │
│ status:        O (Open)                                         │
│ comment:       "Panic alert received"                           │
└─────────────────────────────────────────────────────────────────┘
                            ↓
                    [Sync Service]
                            ↓
              ┌─────────────────────────┐
              │ Timestamp Validation    │
              │ ✓ No timezone conversion│
              │ ✓ Times match exactly   │
              └─────────────────────────┘
                            ↓
┌─────────────────────────────────────────────────────────────────┐
│ PostgreSQL (Target) - alerts_2026_03_04                        │
├─────────────────────────────────────────────────────────────────┤
│ Alert ID: 12345                                                 │
│ createtime:    2026-03-04 10:00:00  ← COPIED & VALIDATED      │
│ receivedtime:  2026-03-04 10:00:05  ← COPIED & VALIDATED      │
│ closedtime:    NULL                  ← COPIED & VALIDATED      │
│ status:        O (Open)              ← COPIED                   │
│ comment:       "Panic alert received"← COPIED                   │
│ synced_at:     2026-03-04 15:30:00  ← ADDED                    │
└─────────────────────────────────────────────────────────────────┘
```

## Scenario 2: Alert Update (Closing Alert)

```
┌─────────────────────────────────────────────────────────────────┐
│ MySQL (Source) - UPDATED                                        │
├─────────────────────────────────────────────────────────────────┤
│ Alert ID: 12345                                                 │
│ createtime:    2026-03-04 10:00:00                             │
│ receivedtime:  2026-03-04 10:00:05                             │
│ closedtime:    2026-03-04 10:30:00  ← CHANGED                  │
│ status:        C (Closed)            ← CHANGED                  │
│ comment:       "Alert resolved"      ← CHANGED                  │
│ closedBy:      "John"                ← CHANGED                  │
└─────────────────────────────────────────────────────────────────┘
                            ↓
                    [Sync Service]
                            ↓
              ┌─────────────────────────┐
              │ Check if exists in PG   │
              │ ✓ Record found (ID:12345)│
              └─────────────────────────┘
                            ↓
              ┌─────────────────────────┐
              │ Fetch existing record   │
              │ from PostgreSQL         │
              └─────────────────────────┘
                            ↓
              ┌─────────────────────────┐
              │ Preserve Timestamps     │
              │ • Use PG createtime     │
              │ • Use PG receivedtime   │
              │ • Use PG closedtime     │
              │ (Ignore MySQL values)   │
              └─────────────────────────┘
                            ↓
┌─────────────────────────────────────────────────────────────────┐
│ PostgreSQL (Target) - alerts_2026_03_04 - UPDATED             │
├─────────────────────────────────────────────────────────────────┤
│ Alert ID: 12345                                                 │
│ createtime:    2026-03-04 10:00:00  ← PRESERVED (unchanged)   │
│ receivedtime:  2026-03-04 10:00:05  ← PRESERVED (unchanged)   │
│ closedtime:    NULL                  ← PRESERVED (unchanged)   │
│ status:        C (Closed)            ← UPDATED                  │
│ comment:       "Alert resolved"      ← UPDATED                  │
│ closedBy:      "John"                ← UPDATED                  │
│ synced_at:     2026-03-04 15:35:00  ← UPDATED                  │
└─────────────────────────────────────────────────────────────────┘
```

## Key Principles

### 🔒 Immutable Fields (Never Change After First Insert)
```
createtime    ─┐
receivedtime  ─┼─→ Set ONCE, preserved FOREVER
closedtime    ─┘
```

### 🔄 Mutable Fields (Update on Every Sync)
```
status        ─┐
comment       ─┤
closedBy      ─┤
panelid       ─┤
seqno         ─┼─→ Updated from MySQL on every sync
zone          ─┤
alarm         ─┤
location      ─┤
priority      ─┤
... (all other fields)
synced_at     ─┘
```

## Validation Flow

```
┌─────────────────────────────────────────────────────────────────┐
│                    Sync Operation Starts                        │
└─────────────────────────────────────────────────────────────────┘
                            ↓
                ┌───────────────────────┐
                │ Does record exist     │
                │ in PostgreSQL?        │
                └───────────────────────┘
                    ↓           ↓
                   NO          YES
                    ↓           ↓
        ┌──────────────┐   ┌──────────────┐
        │ NEW RECORD   │   │ UPDATE       │
        └──────────────┘   └──────────────┘
                ↓                   ↓
    ┌──────────────────┐   ┌──────────────────┐
    │ Validate         │   │ Fetch existing   │
    │ Timestamps       │   │ record from PG   │
    │ (MySQL vs PG)    │   └──────────────────┘
    └──────────────────┘            ↓
            ↓                ┌──────────────────┐
    ┌──────────────────┐   │ Preserve original│
    │ Valid?           │   │ timestamps       │
    └──────────────────┘   │ (from PG)        │
        ↓       ↓           └──────────────────┘
       YES      NO                  ↓
        ↓       ↓           ┌──────────────────┐
        ↓   ┌────────┐     │ Update other     │
        ↓   │ FAIL   │     │ fields           │
        ↓   │ Rollback│    │ (from MySQL)     │
        ↓   └────────┘     └──────────────────┘
        ↓                           ↓
        └───────────┬───────────────┘
                    ↓
        ┌──────────────────────┐
        │ UPSERT to PostgreSQL │
        └──────────────────────┘
                    ↓
        ┌──────────────────────┐
        │ ✅ Success           │
        └──────────────────────┘
```

## Example Timeline

```
Time: 10:00:00 - Alert Created in MySQL
├─ createtime: 10:00:00
├─ receivedtime: 10:00:05
└─ status: O (Open)

Time: 10:05:00 - First Sync to PostgreSQL
├─ Validation: ✅ PASS (timestamps match)
├─ Insert to PG with timestamps: 10:00:00, 10:00:05
└─ PostgreSQL now has: createtime=10:00:00, receivedtime=10:00:05

Time: 10:30:00 - Alert Closed in MySQL
├─ closedtime: 10:30:00
├─ status: C (Closed)
└─ closedBy: "John"

Time: 10:35:00 - Update Sync to PostgreSQL
├─ Check: Record exists ✅
├─ Preserve: createtime=10:00:00 (from PG, not MySQL)
├─ Preserve: receivedtime=10:00:05 (from PG, not MySQL)
├─ Preserve: closedtime=NULL (from PG, not MySQL)
├─ Update: status=C (from MySQL)
├─ Update: closedBy="John" (from MySQL)
└─ PostgreSQL still has: createtime=10:00:00, receivedtime=10:00:05
   (Timestamps NEVER changed!)

Time: 11:00:00 - Another Update in MySQL
├─ comment: "Resolved and verified"
└─ status: C (still closed)

Time: 11:05:00 - Another Sync to PostgreSQL
├─ Check: Record exists ✅
├─ Preserve: createtime=10:00:00 (STILL unchanged)
├─ Preserve: receivedtime=10:00:05 (STILL unchanged)
├─ Preserve: closedtime=NULL (STILL unchanged)
├─ Update: comment="Resolved and verified"
└─ PostgreSQL STILL has: createtime=10:00:00, receivedtime=10:00:05
   (Timestamps remain immutable!)
```

## Why This Matters

### ❌ Without Preservation (Old Behavior)
```
First Insert:  createtime = 10:00:00
Update 1:      createtime = 10:30:00  ← WRONG! Changed!
Update 2:      createtime = 11:00:00  ← WRONG! Changed again!
Result: Lost original creation time
```

### ✅ With Preservation (New Behavior)
```
First Insert:  createtime = 10:00:00
Update 1:      createtime = 10:00:00  ← Preserved
Update 2:      createtime = 10:00:00  ← Still preserved
Result: Original creation time maintained forever
```

## Summary

| Aspect | Behavior |
|--------|----------|
| **First Insert** | Timestamps validated and copied from MySQL |
| **Updates** | Timestamps preserved from PostgreSQL (immutable) |
| **Validation** | Only on first insert (new records) |
| **Other Fields** | Always updated from MySQL |
| **Data Integrity** | Timestamps represent original event times |
| **Timezone** | No conversion (IST preserved) |
