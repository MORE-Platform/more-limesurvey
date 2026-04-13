# User Workflow Documentation – Keycloak User Management


## 1. Purpose

This document defines the requirements and process for providing user data to Redlink for user management purposes.

It ensures a clear understanding between LBI and Redlink regarding:
- Required user information
- Responsibilities of each party
- Secure and compliant handling of personal data


## 2. Scope

This document covers the exchange of user-related information between LBI and Redlink.

It includes:
- Responsibilities for providing and processing user data
- Required user attributes for account management
- Definition of user roles and access requirements
- The process for securely transmitting user data

This document does not include internal technical implementation details within Redlink systems.


## 3. Responsibilities

### LBI
- Provides complete and accurate user information
- Ensures that only necessary personal data is shared
- Defines required user roles and access rights
- Communicates updates and changes in a timely manner

### Designated contact person (LBI)
The designated LBI contact person **Gunnar (Study PI)** maintains and updates the shared user list and informs Redlink of any changes (see Section 3).
 
### Redlink
- Processes the provided user data for user role assignments
- Ensures correct implementation of roles and permissions
- Handles data securely and in accordance with agreed standards


## 4. User creation and role assignment 

Users can be created directly in the platform by LBI via self-registration.

After user creation, LBI provides Redlink with a list of registered users and their assigned roles. Redlink is responsible for linking these users to the corresponding roles in the system. This approach will only use the minimum information to assign roles.

### Required information for role assignment 

The following information must be provided in the user list. The list is sent via email to Redlink for processing.

| Field                     | Description                                   | Required |
| ------------------------- | --------------------------------------------- | -------- |
| E-mail                    | Email address of the user                     | Yes      |
| System Role(s) / Group(s) | Role(s) assigned to the user                  | Yes      |
| Action type               | Type of change (create / update / deactivate) | Yes      |

**Clarification**
- Only **E-mail + Role(s)** are technically required for role assignment in the system
- **Action type** is used to ensure efficient processing of requests in the shared list

### Role assignment process
- LBI creates users in the system (self-sign-up)
- LBI maintains a structured user-role list based on the described conventions (shared tracking list)
- LBI updates this list whenever changes occur and informs Redlink about them
- Redlink links users to roles based on email matching
- Processing is always based on the latest version of the shared list


## 5. User Roles and Access

User roles define the level of access and permissions within the system.

LBI is responsible for defining:
- Which role(s) are assigned to each user

Redlink is responsible for:
- Correctly applying the roles in the system

### Role Definitions

In the keycloak environment we have Realm Roles and Groups, which can be assigned to users. We mainly work with the Groups to assign roles accordingly.

| Group               | Realm Roles    | Description                                                                                                                              |
|---------------------|----------------|------------------------------------------------------------------------------------------------------------------------------------------|
| MORE Administrator  | MORE Admin     | (System Administrator, Platform Administrator): Rights to manage users, emergency functions, no rights to see data or manipulate studies. |
| MORE Researcher     | MORE Viewer    | Can access existing studies (based on assigned study-level roles)                                                                        |

Note: The User Roles and Groups provided by Keycloak are not 1:1 corresponding with the roles inside the MORE platform (Study Administrator, Study Operator, Study Viewer). However a MORE Researcher is able to create a study and assign those Study roles on study level to other users.

### Operational Roles per System

For each deployed system (e.g., study environment), a defined set of roles is required to ensure proper user and access management.

The following roles are established:

#### System Administrator
- Responsible for configuring and managing user roles within the system
- Can assign and adjust roles for other users
- Acts as the main authority for access control within the system

#### User Management Contact
- Responsible for maintaining the shared user-role tracking list
- Coordinates user access requests and updates
- Communicates changes to System Administrator
- This role may be fulfilled by the Study Administrator

##### System Users (Researcher / Viewers)
- Standard users of the system
- Have access to studies based on assigned permissions
- Do not manage roles or user configurations


## 6. Processing by Redlink (High-Level)
Redlink processes user-role assignments based on the latest version of the shared tracking list described in Section 4.

Redlink will:
- Create or update user accounts if required
- Assign or update roles based on email matching
- Apply changes according to the provided action type
- Ensure secure and compliant handling of all data

No further internal processing details are part of this document.
