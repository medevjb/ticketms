# Initial Requirements: Support Ticket & SLA Management System

## Project Overview

Develop a Support Ticket Management System using Laravel. The application should allow users/customers to create support tickets and support agents to manage, respond to, assign, resolve, and track those tickets.

## 01. Ticket Management

Implement the following ticket management capabilities:

- Create a ticket.
- View a ticket.
- Update a ticket.
- Resolve or close a ticket.
- View upcoming or active tickets.
- View resolved or closed tickets.

Each ticket should contain appropriate information, such as:

| Information | Field |
| --- | --- |
| Identification | Unique ticket ID |
| Content | Subject and description |
| Classification | Priority and category |
| Workflow | Status |
| Assignment | Assigned agent |
| Creation | Created by and created date |
| Service target | SLA or due time |

Example statuses include:

- Open
- In Progress
- Waiting for Customer
- Resolved
- Closed

These statuses may be extended or modified if the design requires it.

## 02. Ticket ID Generation

Generate a unique ticket number using a predefined prefix format. For example:

```text
TKT-2026-000001
```

The implementation should handle uniqueness and future growth appropriately.

## 03. Roles and Permissions

The system should contain at least the following roles:

- Administrator
- Support Agent
- Customer/User

Expected authorization behavior:

- Customers should only be able to access their permitted tickets.
- Support agents should only perform actions they are authorized to perform.
- Administrators should have appropriate administrative access.
- Authorization must be enforced on the server side.

The permission structure may be determined as part of the design.

## 04. Ticket Assignment

Tickets should be assignable to support agents. Implement both:

- Manual ticket assignment.
- Automatic ticket assignment.

The automatic assignment strategy may be determined as part of the design. Possible considerations include:

- Agent workload.
- Round-robin assignment.
- Ticket category.
- Agent availability.
- Any other reasonable strategy.

Document the chosen approach.

## 05. Concurrent Ticket Assignment

Consider the scenario in which two support agents attempt to accept the same unassigned ticket at approximately the same time.

The system must ensure that the ticket is not incorrectly assigned to multiple agents. Design an appropriate solution and be prepared to explain its implementation.

## 06. Ticket Conversation

Users and support agents should be able to communicate through a ticket. The system should support:

- Customer replies.
- Agent replies.
- Internal agent notes.

Internal notes must never be visible to customers.

## 07. Attachments

Users and agents should be able to attach files to tickets or replies. The design should give appropriate consideration to:

- File validation.
- File storage.
- File size and type restrictions.
- Secure file access.

## 08. SLA Management

Tickets should have a resolution SLA based on their priority. The following values are examples:

| Priority | Resolution Time |
| --- | --- |
| Low | 48 hours |
| Medium | 24 hours |
| High | 8 hours |
| Critical | 2 hours |

The application should identify ticket SLA conditions such as:

- Within SLA.
- Approaching SLA breach.
- SLA breached.

SLA configuration should allow rules to change without requiring unnecessary changes throughout the application.

## 09. Business Hours

SLA calculations should consider configurable business hours. For example:

| Setting | Example |
| --- | --- |
| Business days | Sunday–Thursday |
| Business hours | 9:00 AM–6:00 PM |

If a Critical ticket with a two-hour SLA is created on Thursday at 5:00 PM, simply adding two clock hours would not correctly calculate its SLA deadline.

Design an appropriate approach for calculating the deadline. The solution does not need to cover every possible enterprise calendar scenario, but its implementation and reasoning should be clear and maintainable.

## 10. SLA Escalation

The application should automatically identify and escalate tickets that are approaching or have exceeded their SLA.

An escalation may include:

- Marking the ticket as escalated.
- Notifying appropriate users.
- Sending an email.
- Creating an escalation activity or history entry.

The exact escalation mechanism may be determined as part of the design.

## 11. Notifications

Send notifications for important ticket events. Examples include:

- Ticket created.
- Ticket assigned.
- Agent replied.
- Customer replied.
- Ticket resolved.
- SLA approaching.
- SLA breached.

Email notifications should be processed appropriately without unnecessarily blocking user requests.

## 12. Search and Filtering

Provide ticket search and filtering functionality. Example search and filter criteria include:

- Ticket ID.
- Keyword.
- Status.
- Priority.
- Category.
- Assigned agent.
- Customer.
- Created date.
- SLA status.

Pagination should be implemented.

## 13. CSV Import

Provide a way to import ticket data from CSV. The import should reasonably support large files and consider:

- Memory usage.
- Validation.
- Invalid rows.
- Duplicate data.
- Error reporting.
- Long-running processing.

The appropriate Laravel implementation may be determined as part of the design.

## 14. Dashboard

Create a simple, useful dashboard. It may contain information such as:

- Total open tickets.
- Unassigned tickets.
- Critical tickets.
- SLA-breached tickets.
- Tickets approaching SLA breach.
- Resolved tickets.
- Tickets by priority.
- Tickets by agent.

Complex visualizations are not required. Evaluation should focus on usability, query quality, and implementation.

## 15. Activity and Audit History

Important ticket activities should be traceable. For example:

| Time | Activity |
| --- | --- |
| 10:01 AM | Ticket created by User A |
| 10:05 AM | Assigned to Agent B |
| 10:20 AM | Priority changed from Medium to High |
| 10:35 AM | Agent replied |
| 11:00 AM | Ticket escalated |
| 11:15 AM | Status changed from In Progress to Resolved |

The activity history should provide enough information to understand what happened to a ticket.

## 16. Database and Performance Considerations

Design the database assuming that the application may eventually contain large numbers of:

- Tickets.
- Ticket replies.
- Attachments.
- Activity records.
- Notifications.

Evaluation areas include:

- Database structure.
- Relationships.
- Indexes.
- Query efficiency.
- Pagination.
- N+1 query handling.
- Eloquent usage.

Generating millions of records is not required.

## 17. Automated Testing

Include automated tests for areas considered important. The exact tests are intentionally unspecified.

Part of the evaluation is identifying which areas are critical enough to test.
