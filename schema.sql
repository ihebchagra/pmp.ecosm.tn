-- Full Schema Drop and Recreate
-- Current Date and Time (for reference): 2025-06-15 08:59:18 UTC
-- User: ihebchagra

-- Drop existing tables and types in a safe order
DROP TABLE IF EXISTS attempt_answers CASCADE;
DROP TABLE IF EXISTS bloc_propositions CASCADE;
DROP TABLE IF EXISTS project_questions CASCADE; -- Old table, ensure it's dropped if it exists from previous versions
DROP TABLE IF EXISTS bloc_images CASCADE;
DROP TABLE IF EXISTS project_images CASCADE;    -- Old table, ensure it's dropped
DROP TABLE IF EXISTS project_blocs CASCADE;
DROP TABLE IF EXISTS project_shares CASCADE;
DROP TABLE IF EXISTS attempts CASCADE;
DROP TABLE IF EXISTS user_projects CASCADE;

DROP TYPE IF EXISTS solution_points_enum CASCADE;
DROP TYPE IF EXISTS project_share_type_enum CASCADE;

-- Create ENUM types
CREATE TYPE solution_points_enum AS ENUM ('dead', '-2', '-1', '0', '1', '2');
CREATE TYPE project_share_type_enum AS ENUM ('exam', 'copy', 'results');

-- Function and Trigger for auto-updating updated_at columns
CREATE OR REPLACE FUNCTION trigger_set_timestamp()
RETURNS TRIGGER AS $$
BEGIN
  NEW.updated_at = NOW();
  RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- 1. user_projects (Stores overall project metadata)
CREATE TABLE user_projects (
  project_id SERIAL PRIMARY KEY,
  user_id VARCHAR(249) NOT NULL, -- user's email as identifier
  project_name VARCHAR(255) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX user_projects_user_id_idx ON user_projects(user_id);
CREATE TRIGGER set_user_projects_updated_at
BEFORE UPDATE ON user_projects
FOR EACH ROW
EXECUTE FUNCTION trigger_set_timestamp();

-- 2. project_blocs (Represents an "énoncé/bloc" within a project)
CREATE TABLE project_blocs (
  bloc_id SERIAL PRIMARY KEY,
  project_id INTEGER NOT NULL REFERENCES user_projects(project_id) ON DELETE CASCADE,
  problem_text TEXT NOT NULL, -- The main text/description of the bloc
  sequence_number INTEGER NOT NULL, -- For ordering blocs within a project
  time_limit_seconds INTEGER, -- Optional custom time limit for this bloc in seconds
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (project_id, sequence_number) -- Ensures unique order per project
);
CREATE INDEX project_blocs_project_id_idx ON project_blocs(project_id);
CREATE TRIGGER set_project_blocs_updated_at
BEFORE UPDATE ON project_blocs
FOR EACH ROW
EXECUTE FUNCTION trigger_set_timestamp();

-- 3. bloc_images (Stores images associated with a specific bloc)
CREATE TABLE bloc_images (
  image_id SERIAL PRIMARY KEY,
  bloc_id INTEGER NOT NULL REFERENCES project_blocs(bloc_id) ON DELETE CASCADE,
  image_path VARCHAR(255) NOT NULL,
  is_deleted BOOLEAN NOT NULL DEFAULT FALSE,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX bloc_images_bloc_id_idx ON bloc_images(bloc_id);

-- 4. bloc_propositions (Represents a question/proposition within a bloc)
CREATE TABLE bloc_propositions (
  proposition_id SERIAL PRIMARY KEY,
  bloc_id INTEGER NOT NULL REFERENCES project_blocs(bloc_id) ON DELETE CASCADE,
  proposition_text TEXT NOT NULL,
  solution_text TEXT NOT NULL, -- Explanation or correct answer text
  solution_points solution_points_enum NOT NULL,
  precedent_proposition_for_penalty_id INTEGER REFERENCES bloc_propositions(proposition_id) ON DELETE SET NULL,
  penalty_value_if_chosen_early solution_points_enum, -- MODIFIED: Now uses the enum
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  -- MODIFIED: New constraint for enum penalty values
  CONSTRAINT chk_penalty_enum_values CHECK (
    penalty_value_if_chosen_early IS NULL OR
    penalty_value_if_chosen_early IN ('dead', '-2', '-1')
  )
  -- NOTE: A trigger or advanced application logic would be beneficial to ensure
  -- 'precedent_proposition_for_penalty_id' belongs to the same 'bloc_id'.
);
CREATE INDEX bloc_propositions_bloc_id_idx ON bloc_propositions(bloc_id);
CREATE INDEX bloc_propositions_precedent_prop_idx ON bloc_propositions(precedent_proposition_for_penalty_id);
CREATE TRIGGER set_bloc_propositions_updated_at
BEFORE UPDATE ON bloc_propositions
FOR EACH ROW
EXECUTE FUNCTION trigger_set_timestamp();

-- 5. attempts (Stores information about each attempt on a project)
CREATE TABLE attempts (
  attempt_id SERIAL PRIMARY KEY,
  student_name VARCHAR(255) NOT NULL,
  project_id INTEGER NOT NULL REFERENCES user_projects(project_id) ON DELETE CASCADE,
  is_guest BOOLEAN NOT NULL DEFAULT FALSE,
  locked BOOLEAN NOT NULL DEFAULT FALSE,
  result INTEGER, -- Overall result/score for the attempt
  stage VARCHAR(255),
  niveau VARCHAR(255),
  centre_exam VARCHAR(255),
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP -- Optional: If attempts can be modified (e.g., when locked or result is set)
);
CREATE INDEX attempts_project_id_idx ON attempts(project_id);
-- Optional trigger for attempts.updated_at if you add the column and want it auto-updated
-- CREATE TRIGGER set_attempts_updated_at
-- BEFORE UPDATE ON attempts
-- FOR EACH ROW
-- EXECUTE FUNCTION trigger_set_timestamp();


-- 6. attempt_answers (Stores answers given for each proposition in an attempt)
CREATE TABLE attempt_answers (
  attempt_id INTEGER NOT NULL REFERENCES attempts(attempt_id) ON DELETE CASCADE,
  proposition_id INTEGER NOT NULL REFERENCES bloc_propositions(proposition_id) ON DELETE CASCADE,
  -- 'answer' column might be tricky. If it's just "chosen" or "not chosen", it's implicit by presence in this table.
  -- If there are different answer types (e.g., free text), then this TEXT field is useful.
  -- For now, its presence indicates it was chosen. We can add more fields like 'score_awarded_for_this_answer' if needed.
  chosen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, -- Tracks when the proposition was chosen by the student
  penalty_applied solution_points_enum, -- Stores the penalty value if one was applied for this choice
  PRIMARY KEY (attempt_id, proposition_id)
);
CREATE INDEX attempt_answers_proposition_id_idx ON attempt_answers(proposition_id);

-- 7. project_shares (Manages sharing of projects with different types and tokens)
CREATE TABLE project_shares (
  share_id SERIAL PRIMARY KEY,
  project_id INTEGER NOT NULL REFERENCES user_projects(project_id) ON DELETE CASCADE,
  share_token VARCHAR(64) NOT NULL UNIQUE,
  share_type project_share_type_enum NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX project_shares_project_id_idx ON project_shares(project_id);

-- Grant permissions (Example for a user 'your_app_user', replace as needed)
-- GRANT ALL PRIVILEGES ON ALL TABLES IN SCHEMA public TO your_app_user;
-- GRANT ALL PRIVILEGES ON ALL SEQUENCES IN SCHEMA public TO your_app_user;
-- GRANT EXECUTE ON FUNCTION trigger_set_timestamp() TO your_app_user;

-- Reminder about the inter-bloc constraint for precedent_proposition_for_penalty_id:
-- It's recommended to enforce via application logic or a more complex database trigger
-- that 'precedent_proposition_for_penalty_id' must reference a 'proposition_id'
-- that belongs to the same 'bloc_id' as the proposition defining the penalty.
-- Example (Conceptual Trigger - requires careful implementation):
/*
CREATE OR REPLACE FUNCTION check_penalty_precedent_bloc_consistency()
RETURNS TRIGGER AS $$
DECLARE
  current_bloc_id INTEGER;
  precedent_bloc_id INTEGER;
BEGIN
  -- Get bloc_id of the current proposition
  SELECT bloc_id INTO current_bloc_id FROM project_blocs WHERE bloc_id = NEW.bloc_id;

  -- If precedent_proposition_for_penalty_id is set, check its bloc_id
  IF NEW.precedent_proposition_for_penalty_id IS NOT NULL THEN
    SELECT bp.bloc_id INTO precedent_bloc_id
    FROM bloc_propositions bp
    WHERE bp.proposition_id = NEW.precedent_proposition_for_penalty_id;

    IF precedent_bloc_id IS NULL OR precedent_bloc_id <> current_bloc_id THEN
      RAISE EXCEPTION 'Precedent proposition for penalty must belong to the same bloc.';
    END IF;
  END IF;
  RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER ensure_penalty_precedent_same_bloc
BEFORE INSERT OR UPDATE ON bloc_propositions
FOR EACH ROW
EXECUTE FUNCTION check_penalty_precedent_bloc_consistency();
*/

-- End of Schema
