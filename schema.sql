-- 1. user_projects
CREATE TABLE user_projects (
  project_id SERIAL PRIMARY KEY,
  user_id VARCHAR(249) NOT NULL, -- user's email as identifier
  project_name VARCHAR(255) NOT NULL,
  problem_text TEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX user_projects_user_id_idx ON user_projects(user_id);

-- 2. project_questions
CREATE TYPE solution_points_enum AS ENUM ('dead', '-2', '-1', '0', '1', '2');
CREATE TABLE project_questions (
  question_id SERIAL PRIMARY KEY,
  project_id INTEGER NOT NULL REFERENCES user_projects(project_id) ON DELETE CASCADE,
  question_text TEXT NOT NULL,
  solution_text TEXT NOT NULL,
  solution_points solution_points_enum NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX project_questions_project_id_idx ON project_questions(project_id);

-- 3. project_images (with soft-delete)
CREATE TABLE project_images (
  image_id SERIAL PRIMARY KEY,
  project_id INTEGER NOT NULL REFERENCES user_projects(project_id) ON DELETE CASCADE,
  image_path VARCHAR(255) NOT NULL,
  is_deleted BOOLEAN NOT NULL DEFAULT FALSE,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX project_images_project_id_idx ON project_images(project_id);

-- 4. attempts
CREATE TABLE attempts (
  attempt_id SERIAL PRIMARY KEY,
  student_name VARCHAR(255) NOT NULL,
  project_id INTEGER NOT NULL REFERENCES user_projects(project_id) ON DELETE CASCADE,
  is_guest BOOLEAN NOT NULL DEFAULT FALSE,   -- <--- Add this!
  locked BOOLEAN NOT NULL DEFAULT FALSE,
  result INTEGER,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX attempts_project_id_idx ON attempts(project_id);

-- 5. attempt_answers
CREATE TABLE attempt_answers (
  attempt_id INTEGER NOT NULL REFERENCES attempts(attempt_id) ON DELETE CASCADE,
  question_id INTEGER NOT NULL REFERENCES project_questions(question_id) ON DELETE CASCADE,
  answer TEXT,
  PRIMARY KEY (attempt_id, question_id)
);
CREATE INDEX attempt_answers_question_id_idx ON attempt_answers(question_id);

-- 6. project_shares
CREATE TABLE project_shares (
  project_id INTEGER PRIMARY KEY REFERENCES user_projects(project_id) ON DELETE CASCADE,
  share_token VARCHAR(64) UNIQUE NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
