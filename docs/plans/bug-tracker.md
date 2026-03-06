# Bug Tracker

Suspected bugs and issues to investigate. Claude logs items here when something looks wrong during development. Review periodically and promote confirmed bugs to issues or fix them directly.

## How to Use

- **Suspected**: Observed during development but not yet confirmed or root-caused
- **Confirmed**: Reproduced and understood, needs a fix
- **Investigating**: Actively being looked into
- **Fixed**: Resolved (move to Fixed section with date and brief note)

## Suspected Bugs

_None yet._

<!-- Template:
### [Short description]
- **Observed**: [date]
- **Status**: Suspected / Confirmed / Investigating
- **Context**: [What was happening when this was noticed]
- **Symptoms**: [What went wrong or looked wrong]
- **Files involved**: [Relevant files if known]
- **Notes**: [Any additional context]
-->

## Confirmed Bugs

_None yet._

## Fixed

### Ambiguous `id` column in UserController::updateGroups on SQLite
- **Observed**: 2026-03-05
- **Fixed**: 2026-03-05
- **Fix**: Changed `pluck('id')` to `pluck('user_groups.id')` in `UserController.php:290` to qualify the column name in the join query.
