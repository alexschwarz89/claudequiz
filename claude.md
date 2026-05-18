## Code Quality Standards

- English language in code, comments, filenames and commit messages
- Single Responsibility Principle — one class, one purpose
- Cyclomatic complexity ≤5 — extract branches into methods
- Guard clauses — return/throw early, avoid nested if/else
- Descriptive names — no abbreviations, no single letters outside loops
- Methods ≤50 lines
- Constants/enums over magic values
- Always declare return types, including `void`
- Controllers/Commands: validate → call service → return; no SQL, no business logic
- No unnecessary inline comments
- Max 5 params — else use a DTO or parameter object
- Follow DDD + Symfony directory conventions
- Use bulletproof libraries (e.g. Symfony components, Doctrine, League/CSV) over custom code
- No html generation in PHP — use templates
- Use readonly properties where possible
