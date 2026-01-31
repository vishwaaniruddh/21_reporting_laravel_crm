# Testing Protocol: Two-Phase Approach

## ⚠️ CRITICAL SAFETY REQUIREMENT

This document outlines the mandatory two-phase testing approach for the MySQL Alerts Age-Based Cleanup feature.

## Phase 1: Testing Phase (alerts_2 table)

### Objective
Complete all development, testing, and validation on the `alerts_2` table to ensure the system works correctly before touching production data.

### Requirements
1. **Target Table**: `alerts_2` (testing table)
2. **Production Table**: `alerts` (MUST NOT be touched)
3. **Configuration Default**: All code must default to `alerts_2`
4. **Duration**: Until all tests pass and explicit approval is given

### Implementation Rules
- Every database query MUST use the configurable `targetTable` property
- NEVER hardcode table name as `'alerts'` in any code
- All tests MUST verify operations only affect `alerts_2`
- Configuration MUST default to `alerts_2`

### Configuration Settings (Phase 1)
```env
CLEANUP_TARGET_TABLE=alerts_2
CLEANUP_AGE_THRESHOLD_HOURS=48
CLEANUP_BATCH_SIZE=100
CLEANUP_ENABLED=false  # Manual trigger only during testing
```

### Code Configuration (Phase 1)
```php
class AgeBasedCleanupService
{
    private string $targetTable = 'alerts_2'; // ⚠️ CRITICAL: Testing phase
    private int $ageThresholdHours = 48;
    private int $batchSize = 100;
}
```

### Validation Checklist (Phase 1)
- [ ] All unit tests pass
- [ ] All property tests pass (100+ iterations each)
- [ ] Integration tests complete successfully
- [ ] Dry run mode tested and verified
- [ ] Emergency stop mechanism tested
- [ ] Performance metrics meet targets
- [ ] `alerts` table has NEVER been modified
- [ ] All operations confirmed to only affect `alerts_2`
- [ ] Code review completed
- [ ] Documentation reviewed

### Exit Criteria
Phase 1 is complete when:
1. All validation checklist items are checked
2. System has been running successfully on `alerts_2` for at least 1 week
3. No critical issues found
4. Explicit approval received from stakeholders

---

## Phase 2: Production Phase (alerts table)

### ⚠️ DO NOT PROCEED WITHOUT EXPLICIT APPROVAL

### Objective
Migrate the cleanup system to work with the production `alerts` table after successful Phase 1 completion.

### Prerequisites
- [ ] Phase 1 completely finished
- [ ] All Phase 1 validation checklist items completed
- [ ] Explicit written approval from stakeholders
- [ ] Rollback plan documented and reviewed
- [ ] Monitoring dashboard ready
- [ ] Alert system configured

### Configuration Changes (Phase 2)
```env
CLEANUP_TARGET_TABLE=alerts  # ⚠️ PRODUCTION TABLE
CLEANUP_AGE_THRESHOLD_HOURS=48
CLEANUP_BATCH_SIZE=100
CLEANUP_ENABLED=true  # Enable automated cleanup
```

### Code Configuration (Phase 2)
```php
class AgeBasedCleanupService
{
    private string $targetTable = 'alerts'; // ⚠️ CRITICAL: Production phase
    private int $ageThresholdHours = 48;
    private int $batchSize = 100;
}
```

### Migration Steps
1. **Preparation**
   - Review all Phase 1 test results
   - Verify monitoring is in place
   - Confirm backup procedures
   - Schedule maintenance window if needed

2. **Configuration Update**
   - Update environment variable: `CLEANUP_TARGET_TABLE=alerts`
   - Update code configuration if needed
   - Clear configuration cache
   - Restart services

3. **Initial Production Run**
   - Start with dry run mode on `alerts` table
   - Review dry run results carefully
   - Execute first cleanup with small batch size (10-20 records)
   - Monitor closely for 24 hours

4. **Gradual Rollout**
   - Increase batch size gradually
   - Monitor performance metrics
   - Watch for any anomalies
   - Enable automated scheduling after 1 week of successful manual runs

### Rollback Plan
If issues are detected:
1. Set emergency stop flag immediately
2. Revert configuration to `alerts_2`
3. Investigate issues
4. Fix problems
5. Return to Phase 1 testing

### Monitoring (Phase 2)
- Real-time alerts for cleanup failures
- Dashboard showing records deleted per run
- Performance metrics (batch time, throughput)
- Error rate monitoring
- Table size tracking

---

## Safety Mechanisms (Both Phases)

### Multi-Layer Verification
1. **Age Threshold**: Only records older than 48 hours
2. **PostgreSQL Verification**: Record must exist in PostgreSQL
3. **Batch Size Limits**: 10-1000 records per batch
4. **Admin Confirmation**: Required for all operations
5. **Emergency Stop**: Can halt operations immediately
6. **Comprehensive Logging**: All operations logged

### Emergency Procedures
- **Emergency Stop**: Set flag via API or database
- **Rollback**: Revert configuration to previous table
- **Support Contact**: [Add contact information]

---

## Summary

| Phase | Target Table | Status | Production Impact |
|-------|-------------|--------|-------------------|
| Phase 1 | `alerts_2` | Active | NONE - Testing only |
| Phase 2 | `alerts` | Pending Approval | HIGH - Production data |

**Current Phase**: Phase 1 (Testing)
**Next Milestone**: Complete all Phase 1 validation checklist items
**Production Migration**: Requires explicit approval after Phase 1 completion
