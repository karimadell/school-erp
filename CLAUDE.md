# School ERP AI Development Guide

## Project Overview

This project is a modern School ERP system built with Laravel.

The long-term vision is to build a scalable SaaS platform that can serve:
- Schools
- Kindergartens
- Educational Centers
- Universities
- Training Institutes

The system must be production-ready, secure, maintainable, and scalable.

---

# Technology Stack

- Laravel 12
- PHP 8.5
- Blade
- Tailwind CSS
- Vite
- MySQL

---

# Architecture

The application follows a modular architecture.

Business logic should be separated from controllers.

Prefer:

- Services
- Policies
- Form Requests
- Blade Components
- View Models (when needed)

Keep controllers thin.

Avoid duplicated logic.

---

# Coding Standards

Always follow Laravel best practices.

Rules:

- Use meaningful names.
- Keep methods small.
- Keep controllers lightweight.
- Reuse components.
- Never duplicate code.
- Prefer dependency injection.
- Use Eloquent relationships correctly.
- Use eager loading whenever needed.
- Avoid N+1 queries.
- Write readable code before clever code.
- Follow PSR standards.

---

# Database Rules

Never delete production data.

Never use:

- migrate:fresh
- migrate:reset
- db:wipe

Always create new migrations.

Never rename existing columns unless absolutely necessary.

Respect foreign keys.

Optimize indexes only when needed.

---

# UI / UX Standards

The interface must be:

- Modern
- Minimal
- Responsive
- Accessible
- Clean
- Consistent

Always support:

- Desktop
- Tablet
- Mobile

Use reusable Blade components.

Avoid inconsistent spacing.

Maintain a unified design system.

---

# Localization

Supported languages:

- Russian
- English
- Arabic

Rules:

- Never hardcode text.
- Always use translation keys.
- Preserve RTL support.
- Preserve LTR layouts.
- Every new feature must support all languages.

---

# Roles & Permissions

Never bypass authorization.

Respect existing roles.

Respect existing permissions.

Do not expose unauthorized data.

Protect both:

- Backend
- Frontend

---

# Performance Rules

Dashboard queries must be optimized.

Avoid:

- N+1 queries
- unnecessary loops
- duplicated queries

Use:

- eager loading
- aggregates
- caching when appropriate

Never optimize at the expense of data accuracy.

---

# Testing Rules

Every completed feature should be tested.

Run only relevant tests.

Never ignore failing tests.

Verify:

- Routes
- Permissions
- Dashboard
- Localization
- RTL
- Responsive layout

---

# Documentation Rules

Whenever a major feature is completed:

Update:

- docs/CHANGELOG.md
- docs/06_Roadmap.md

Update any affected documentation.

Documentation must always match the code.

---

# Git Rules

Never commit:

- vendor/
- node_modules/
- .env

Keep commits focused.

One logical feature per commit.

Write meaningful commit messages.

---

# AI Working Rules

Before writing code:

1. Inspect the existing implementation.
2. Understand current architecture.
3. Reuse existing components.
4. Preserve compatibility.
5. Do not break existing modules.
6. Do not remove working functionality.
7. Explain major architectural decisions.
8. Test before considering the task complete.
9. Update documentation.
10. Deliver production-quality code.

If requirements are unclear:

- Inspect the project.
- Make the safest engineering decision.
- Document the decision.

Never guess database columns or routes.

---

# Feature Development Workflow

Every feature must follow:

1. Inspect
2. Analyze
3. Design
4. Implement
5. Refactor
6. Test
7. Document
8. Review
9. Deliver

Never skip these steps.

---

# Folder Responsibilities

app/
Business Logic

resources/
Blade Views

routes/
Application Routes

database/
Schema & Migrations

lang/
Translations

tests/
Testing

docs/
Project Documentation

prompts/
AI Task Instructions

---

# Long-Term Vision

This project should evolve into a commercial SaaS School ERP platform.

Every architectural decision should prioritize:

- Scalability
- Security
- Maintainability
- Performance
- Developer Experience
- User Experience