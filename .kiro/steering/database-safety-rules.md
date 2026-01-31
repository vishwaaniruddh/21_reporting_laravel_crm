---
inclusion: always
---

# Database Safety Rules

## CRITICAL: Dangerous Database Commands

**NEVER execute these commands without EXPLICIT double confirmation from the user:**

### Absolutely Forbidden Commands (Require Double Confirmation)

❌ `php artisan migrate:fresh` - Drops ALL tables and re-runs migrations
❌ `php artisan migrate:reset` - Rolls back ALL migrations (drops tables)
❌ `php artisan migrate:rollback` - Rolls back migrations (can drop tables)
❌ `php artisan db:wipe` - Drops ALL tables and views
❌ Any command with `--force` flag in production environment
❌ `php artisan migrate:refresh` - Rolls back and re-runs migrations

### Double Confirmation Protocol

When a user requests ANY of the above commands:

1. **STOP immediately** - Do not execute the command
2. **Warn the user** with clear explanation:
   - What data will be lost
   - That this action is irreversible
   - The potential impact on production systems
3. **Ask for explicit confirmation** using this exact format:
   ```
   ⚠️ DANGER: This command will DROP ALL TABLES and DELETE ALL DATA.
   
   Command: [exact command]
   Impact: [specific impact]
   
   This action is IRREVERSIBLE. All data will be permanently lost.
   
   Are you absolutely sure you want to proceed? 
   Type "YES, DELETE ALL DATA" to confirm.
   ```
4. **Only proceed** if user types the exact confirmation phrase

### Safe Alternatives

Instead of dangerous commands, prefer:
- ✅ `php artisan migrate` - Run pending migrations only
- ✅ `php artisan migrate:status` - Check migration status
- ✅ Backup database before any destructive operation
- ✅ Test migrations in development environment first

### Production Environment

In production environments:
- **NEVER** use any of the forbidden commands
- **ALWAYS** create database backups before migrations
- **ALWAYS** test migrations in staging first
- **NEVER** use `--force` flag

## Implementation Note

This rule applies to:
- Direct command execution
- Commands in scripts or automation
- Migration-related tasks
- Database seeding operations
- Any operation that modifies database schema destructively
