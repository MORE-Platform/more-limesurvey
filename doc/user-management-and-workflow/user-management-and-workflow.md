# User Workflow Documentation – Keycloak User Management

## 1. Introduction

### Purpose
This document defines how user data is provided to Redlink for user management in the P2R context.

It ensures a clear understanding between LBI and Redlink regarding:
- Required user information
- Responsibilities of each party
- Secure and compliant handling of personal data

### Scope
This document covers the exchange of user-related data between LBI and Redlink, including:

It includes:
- Responsibilities for providing and processing user data
- Required user attributes for account management
- Definition of user roles and access requirements
- The process for securely transmitting user data

It does not describe internal implementation details within Redlink systems.

--- 

## 2. Operational roles and responsibilities (High level)

For each deployed system (e.g., study environment), a defined set of roles is required to ensure proper user and access management.

The following roles are established:

### System Administrator
- Responsible for configuring and managing user roles within the system
- Can assign and adjust roles for other users
- Acts as the main authority for access control within the system

### User Management Contact
- Responsible for maintaining the shared user-role tracking list
- Coordinates user access requests and updates
- Communicates changes to System Administrator
- This role may be fulfilled by the Study Administrator

### System Users (Researcher / Viewers)
- Standard users of the system
- Have access to studies based on assigned permissions
- Do not manage roles or user configurations

---

## 3. P2R context: Responsibilities

In the P2R context, responsibilities are assigned as follows:

### LBI (User Management and System Users)
- Signs up their users in the self-register process or
- provides accurate user information list via email (see section 4)
- Communicates updates and changes in a timely manner

Assigned contact: **Gunnar (Study PI)**
 
### Redlink (Study Administrator)
- Acts as System Administrator in the P2R context
- Creates, updates, or deactivates users based on provided input
- Assigns roles according to agreed definitions
- Ensures secure and correct implementation of roles and permissions

---

## 4. P2R: User flow

### 4.1 Keycloak Roles and Groups

Keycloak is used for authentication and role assignment. The following groups are defined:

| Group               | Realm Roles       | Description                                                                                                                              |
|---------------------|-------------------|------------------------------------------------------------------------------------------------------------------------------------------|
| MORE Administrator  | incl. MORE Admin  | (System Administrator, Platform Administrator): Rights to manage users, emergency functions, no rights to see data or manipulate studies. |
| MORE Researcher     | incl. MORE Viewer | Can create new studies and access existing studies (based on assigned study-level roles)                                                 |
**Default role:** All newly created users are assigned MORE Researcher unless explicitly specified otherwise.

**Note:** The User Roles and Groups provided by Keycloak are not 1:1 corresponding with the roles inside the MORE platform (Study Administrator, Study Operator, Study Viewer). 

---

### 4.2 User Provisioning Options

#### 1. Self-registration
- LBI registers thier Users directly via the Studymanager self sign-up process
- Default role: MORE Researcher
- No further action required unless role changes are needed

#### 2. Providing a list of users
- LBI may provide a user list to Redlink for provisioning and updates.
- Redlink will create or update users accordingly.

---

### 4.3 Required information for role assignment 

The following information must be provided in the user list. The list is sent via email to Redlink for processing.

| Field                     | Description                                | Required |
| ------------------------- |--------------------------------------------|----------|
| E-mail                    | Email address of the user                  | Yes      |
| System Role(s) / Group(s) | Role(s) assigned to the user               | No       |
| Action type               | Type of change (new / update / deactivate) | Yes      |

**Clarification**
- If no role is provided, MORE Researcher is assigned by default.
- Only **E-mail + Role(s)** are technically required for role assignment in the system
- **Action type** is used to ensure efficient processing of requests in the shared list

---

## 5. Processing by Redlink (High-Level)
Redlink processes user provisioning requests based on the provided user list and action type.

Redlink will:
- Create or update user accounts if required
- Deactivate user accounts if requested
- Assign or update roles based on email matching
- Match users via email address (if needed)
- Ensure secure and compliant handling of all data

All data is handled securely and according to agreed compliance standards.
