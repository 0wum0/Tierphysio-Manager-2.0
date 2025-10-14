# Cursor Rules for Tierphysio Manager 2.0

## 🎯 Project Context

**Always read these files before any coding session:**
- `/scripts/prd.txt` - Complete project documentation
- `/scripts/tasks.json` - Current task list and priorities
- `/scripts/cursor-rules.md` - These development guidelines

## 📋 Development Workflow

### 1. Task Management
- Work on **ONE task at a time** from `tasks.json`
- Update task status in `tasks.json` as you progress
- Never modify working code outside the current task scope
- Complete current task before starting another

### 2. Before Starting Any Task
```bash
# 1. Check current git status
git status

# 2. Read the relevant files for your task
# 3. Test current functionality
# 4. Plan your changes
```

### 3. While Working
- Make incremental changes
- Test after each significant modification
- Keep existing functionality intact
- Document any breaking changes

### 4. After Completing a Task
```bash
# 1. Test all modified endpoints
php /workspace/api/integrity_json.php

# 2. Verify no regression
# 3. Update task status in tasks.json
# 4. Commit with proper message format
```

## 🗂️ Folder Structure Reference

```
/workspace/
├── /api/           → JSON endpoints (always return JSON)
├── /includes/      → PHP classes and helpers
├── /templates/     → Twig templates only
├── /public/        → Entry points and assets
├── /migrations/    → Database schema changes
├── /data/          → SQLite database files
├── /uploads/       → User uploaded files
├── /backups/       → Automated backups
├── /logs/          → Application logs
└── /scripts/       → Project documentation
```

## 🔧 Coding Standards

### PHP Guidelines
```php
// Always use type hints
function getPatient(int $id): ?array {
    // Implementation
}

// Use null coalescing operator
$value = $_GET['id'] ?? null;

// Prepared statements for ALL queries
$stmt = $pdo->prepare("SELECT * FROM patients WHERE id = ?");
$stmt->execute([$id]);

// Return consistent JSON structure
return [
    'success' => true,
    'data' => $result,
    'message' => 'Operation successful'
];
```

### JavaScript Guidelines
```javascript
// Use modern ES6+ syntax
const fetchPatient = async (id) => {
    try {
        const response = await fetch(`/api/patients.php?id=${id}`);
        const data = await response.json();
        return data;
    } catch (error) {
        console.error('Error:', error);
    }
};

// Always handle errors
// Use const/let, never var
// Prefer arrow functions
```

### Twig Templates
```twig
{# Always extend base layout #}
{% extends 'layouts/base.twig' %}

{# Use blocks for content #}
{% block content %}
    <!-- Your content here -->
{% endblock %}

{# Escape output by default #}
{{ variable }}  {# Auto-escaped #}
{{ variable|raw }}  {# Only for trusted HTML #}
```

## 🛡️ Security Requirements

### Authentication
- Check user session on every page
- Validate user permissions for actions
- Use CSRF tokens for all forms
- Session timeout after 30 minutes of inactivity

### Input Validation
```php
// Validate all input
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) {
    http_response_code(400);
    exit(json_encode(['error' => 'Invalid ID']));
}

// Sanitize text input
$name = htmlspecialchars($_POST['name'] ?? '', ENT_QUOTES, 'UTF-8');
```

### File Uploads
```php
// Check file type
$allowed = ['jpg', 'jpeg', 'png', 'pdf'];
$ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
if (!in_array($ext, $allowed)) {
    die('Invalid file type');
}

// Generate unique filename
$filename = uniqid() . '.' . $ext;
```

## 🔍 API Standards

### Response Format
```json
// Success response
{
    "success": true,
    "data": { ... },
    "message": "Operation completed"
}

// Error response
{
    "success": false,
    "error": "Error message",
    "code": 400
}

// List response
{
    "success": true,
    "data": [...],
    "total": 100,
    "page": 1,
    "per_page": 20
}
```

### HTTP Status Codes
- `200` - Success (GET, PUT)
- `201` - Created (POST)
- `204` - No Content (DELETE)
- `400` - Bad Request
- `401` - Unauthorized
- `403` - Forbidden
- `404` - Not Found
- `500` - Server Error

## 📝 Git Commit Format

```bash
# Format: task:<id> - <title> - <summary>

# Examples:
git commit -m "task:1 - Fix patient modal - Correct owner and appointments display"
git commit -m "task:2 - Fix search dropdown - Full-width responsive design"
git commit -m "fix: Resolve SQL injection in patients API"
git commit -m "feat: Add PDF export for treatments"
git commit -m "docs: Update API documentation"
```

### Commit Types
- `task:X` - Task from tasks.json
- `feat:` - New feature
- `fix:` - Bug fix
- `docs:` - Documentation
- `style:` - Formatting, no code change
- `refactor:` - Code restructuring
- `test:` - Adding tests
- `chore:` - Maintenance

## ✅ Testing Checklist

### Before Each Commit
1. [ ] API endpoints return valid JSON
2. [ ] No PHP errors or warnings
3. [ ] JavaScript console clean
4. [ ] Forms submit correctly
5. [ ] Modal windows function
6. [ ] Search works properly
7. [ ] Mobile responsive

### Test Commands
```bash
# Test all API endpoints
php /workspace/api/integrity_json.php

# Check database integrity
php /workspace/integrity/db_check.php

# Validate JSON responses
curl -X GET http://localhost/api/patients.php
```

## 🚀 Performance Guidelines

### Database
- Use indexes on frequently queried columns
- Limit result sets with pagination
- Cache frequently accessed data
- Use JOIN instead of multiple queries

### Frontend
- Lazy load images
- Debounce search input
- Use event delegation
- Minimize DOM manipulation
- Cache API responses when appropriate

### General
- Enable PHP OPcache
- Compress assets (gzip)
- Use CDN for libraries
- Optimize images before upload

## 📚 Common Patterns

### CRUD Operations
```php
// Standard CRUD pattern for all entities
class PatientController {
    public function index() { /* List all */ }
    public function show($id) { /* Get one */ }
    public function create($data) { /* Create new */ }
    public function update($id, $data) { /* Update existing */ }
    public function delete($id) { /* Delete one */ }
}
```

### Modal Management
```javascript
// Standard modal pattern
function openModal(modalId, data) {
    const modal = document.getElementById(modalId);
    populateModal(modal, data);
    modal.classList.remove('hidden');
}

function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    modal.classList.add('hidden');
}
```

### Error Handling
```php
try {
    // Operation
    $result = performOperation();
    echo json_encode(['success' => true, 'data' => $result]);
} catch (Exception $e) {
    error_log($e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Internal server error']);
}
```

## 🎨 UI/UX Standards

### Design System
- Primary color: Blue (#3B82F6)
- Success: Green (#10B981)
- Warning: Yellow (#F59E0B)
- Error: Red (#EF4444)
- Neutral: Gray scale

### Components
- Buttons: Rounded, with hover states
- Cards: Shadow, rounded corners
- Forms: Label above input
- Tables: Striped rows, hover effect
- Modals: Centered, overlay background

### Responsive Breakpoints
- Mobile: < 640px
- Tablet: 640px - 1024px
- Desktop: > 1024px

## 🔄 Continuous Improvement

### Code Reviews
- Self-review before commit
- Check for security issues
- Verify performance impact
- Ensure code readability

### Documentation
- Update README for new features
- Document API changes
- Add inline comments for complex logic
- Keep tasks.json current

### Monitoring
- Check error logs daily
- Monitor performance metrics
- Track user feedback
- Regular security audits

## 📌 Meta Configuration

```yaml
branch: main
environment: development
php_version: 8.3
database: sqlite
framework: custom
template_engine: twig
css_framework: tailwindcss
js_framework: alpine.js
testing: manual
deployment: manual
```

## 🚨 Critical Rules

1. **NEVER** commit credentials or sensitive data
2. **NEVER** skip input validation
3. **NEVER** use raw SQL without prepared statements
4. **NEVER** trust user input
5. **NEVER** modify production database without backup
6. **ALWAYS** test before committing
7. **ALWAYS** handle errors gracefully
8. **ALWAYS** maintain backwards compatibility
9. **ALWAYS** document breaking changes
10. **ALWAYS** follow the single responsibility principle

## 📞 Quick Reference

### Database Connection
```php
require_once __DIR__ . '/../includes/db.php';
$db = getDB();
```

### Session Check
```php
require_once __DIR__ . '/../includes/auth.php';
requireAuth();
```

### Template Rendering
```php
require_once __DIR__ . '/../includes/template.php';
echo renderTemplate('pages/dashboard.twig', ['data' => $data]);
```

### API Response
```php
header('Content-Type: application/json');
echo json_encode($response);
```

---

**Remember:** Quality over speed. It's better to do things right the first time than to fix bugs later.

**Last Updated:** 2024-10-14
**Version:** 2.0.0