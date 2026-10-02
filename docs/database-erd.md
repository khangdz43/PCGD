# PCGD Database ERD

```mermaid
erDiagram
    users {
        bigint id PK
        varchar name
        varchar email UK
        timestamp email_verified_at
        varchar password
        varchar remember_token
        timestamp created_at
        timestamp updated_at
    }

    password_reset_tokens {
        varchar email PK
        varchar token
        timestamp created_at
    }

    sessions {
        varchar id PK
        bigint user_id FK
        varchar ip_address
        text user_agent
        text payload
        int last_activity
    }

    cache {
        varchar key PK
        text value
        int expiration
    }

    cache_locks {
        varchar key PK
        varchar owner
        int expiration
    }

    jobs {
        bigint id PK
        varchar queue
        text payload
        tinyint attempts
        int reserved_at
        int available_at
        int created_at
    }

    job_batches {
        varchar id PK
        varchar name
        int total_jobs
        int pending_jobs
        int failed_jobs
        text failed_job_ids
        text options
        int cancelled_at
        int created_at
        int finished_at
    }

    failed_jobs {
        bigint id PK
        varchar uuid UK
        text connection
        text queue
        text payload
        text exception
        timestamp failed_at
    }

    import_logs {
        bigint id PK
        varchar file_name
        varchar uploaded_by
        int total_rows
        int success_rows
        int error_rows
        varchar status
        json error_details
        timestamp created_at
    }

    provinces {
        varchar code PK
        varchar name
        timestamp created_at
        timestamp updated_at
    }

    communes {
        varchar code PK
        varchar name
        varchar province_code FK
        timestamp created_at
        timestamp updated_at
    }

    villages {
        varchar code PK
        varchar name
        varchar commune_code FK
        timestamp created_at
        timestamp updated_at
    }

    schools {
        bigint id PK
        varchar code UK
        varchar name
        varchar level
        varchar province_code FK
        varchar commune_code FK
        timestamp created_at
        timestamp updated_at
    }

    ethnicities {
        varchar code PK
        varchar name UK
        timestamp created_at
        timestamp updated_at
    }

    households {
        bigint id PK
        varchar household_code
        varchar school_year
        varchar head_first_name
        varchar head_last_name
        varchar province_code FK
        varchar commune_code FK
        varchar village_code FK
        text address
        enum residence_type
        varchar residence_status
        bigint import_log_id FK
        timestamp created_at
        timestamp updated_at
    }

    persons {
        bigint id PK
        bigint household_id FK
        bigint import_log_id FK
        varchar first_name
        varchar last_name
        date dob
        varchar dob_str
        varchar gender
        varchar ethnicity_code FK
        varchar religion
        varchar priority_type
        varchar relationship_with_head
        varchar parent_name
        varchar phone
        text note
        timestamp created_at
        timestamp updated_at
    }

    person_educations {
        bigint id PK
        bigint person_id FK
        bigint import_log_id FK
        varchar school_year
        varchar academic_block
        varchar current_class
        varchar school_code FK
        varchar graduation_level
        boolean is_complementary
        varchar graduation_year
        varchar vocational_grad_level
        varchar vocational_grad_year
        varchar finished_class
        varchar finished_year
        varchar dropped_class
        varchar dropped_year
        varchar literacy_current_class
        varchar literacy_completed_class
        tinyint literacy_relapse_level
        varchar learning_capacity
        timestamp created_at
        timestamp updated_at
    }

    person_disabilities {
        bigint id PK
        bigint person_id FK
        boolean mobility_disability
        boolean hearing_speech_disability
        boolean visual_disability
        boolean mental_disability
        boolean intellectual_disability
        boolean learning_disability
        boolean autism
        boolean other_disability
        boolean has_disability_cert
        boolean can_study
        varchar special_circumstance
        text special_circumstance_detail
        timestamp created_at
        timestamp updated_at
    }

    import_logs ||--o{ households : imports
    import_logs ||--o{ persons : imports
    import_logs ||--o{ person_educations : imports

    provinces ||--o{ communes : contains
    communes ||--o{ villages : contains
    provinces o|--o{ schools : locates
    communes o|--o{ schools : locates

    provinces o|--o{ households : locates
    communes o|--o{ households : locates
    villages o|--o{ households : locates

    households ||--o{ persons : has
    ethnicities o|--o{ persons : classifies
    persons ||--o{ person_educations : studies
    schools o|--o{ person_educations : attends
    persons ||--o| person_disabilities : records
```

## Database rules not visible as relationships

- `households` has a unique constraint on `(school_year, household_code)`.
- `person_educations` has a unique constraint on `(person_id, school_year)`.
- Database triggers `persons_one_head_before_insert` and `persons_one_head_before_update` enforce at most one person with `relationship_with_head = 'Chủ hộ'` per household.
- Deleting a household cascades to its persons; deleting a person cascades to education and disability records.
- `import_logs` are nullable parents for imported household, person, and education rows; deleting an import log sets those foreign keys to `NULL`.
- `sessions.user_id` is indexed but is not defined as a foreign key in the Laravel migration.

```

```
