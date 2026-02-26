-- MySQL-compatible export generated from data/app.db
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;
DROP TABLE IF EXISTS login_attempts;
DROP TABLE IF EXISTS stage_templates;
DROP TABLE IF EXISTS events;
DROP TABLE IF EXISTS notifications;
DROP TABLE IF EXISTS audit_logs;
DROP TABLE IF EXISTS ideas;
DROP TABLE IF EXISTS comments;
DROP TABLE IF EXISTS tasks;
DROP TABLE IF EXISTS stages;
DROP TABLE IF EXISTS project_members;
DROP TABLE IF EXISTS projects;
DROP TABLE IF EXISTS invites;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS migrations;

CREATE TABLE migrations (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL UNIQUE, applied_at DATETIME NOT NULL);
INSERT INTO migrations VALUES(1,'001_initial.php','2026-02-24 07:15:38');
INSERT INTO migrations VALUES(2,'002_default_stage_templates.php','2026-02-24 07:15:38');
INSERT INTO migrations VALUES(3,'003_member_status_and_turkish_statuses.php','2026-02-24 07:43:44');
INSERT INTO migrations VALUES(4,'004_performance_indexes.php','2026-02-24 11:01:24');
INSERT INTO migrations VALUES(5,'005_stage_template_expansion.php','2026-02-24 13:16:13');
INSERT INTO migrations VALUES(6,'006_user_username_and_profile.php','2026-02-24 14:01:53');
CREATE TABLE users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(120) NOT NULL,
        email VARCHAR(190) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        role VARCHAR(20) NOT NULL DEFAULT 'member',
        avatar_url TEXT NULL,
        created_at DATETIME NOT NULL
    , username VARCHAR(80) NULL, title VARCHAR(120) NULL);
INSERT INTO users VALUES(1,'System Admin','admin@local','$2y$10$Jj0l7vJM.tSJPMM7sFGB.OoS9z/bRC7uR7mpB9XFObQa1kqiyTjNm','admin',NULL,'2026-02-24 07:15:39','admin','Yönetici');
CREATE TABLE invites (
        id INT AUTO_INCREMENT PRIMARY KEY,
        token VARCHAR(128) NOT NULL UNIQUE,
        created_by INT NOT NULL,
        expires_at DATETIME NOT NULL,
        used_by INT NULL,
        used_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        FOREIGN KEY(created_by) REFERENCES users(id),
        FOREIGN KEY(used_by) REFERENCES users(id)
    );
INSERT INTO invites VALUES(1,'869bd974e2a80df11f8ab5e97916620edd64',1,'2026-03-03 13:32:12',NULL,NULL,'2026-02-24 13:32:12');
CREATE TABLE projects (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(180) NOT NULL,
        description TEXT NULL,
        type VARCHAR(32) NOT NULL DEFAULT 'other',
        client VARCHAR(180) NULL,
        status VARCHAR(32) NOT NULL DEFAULT 'active',
        priority VARCHAR(20) NOT NULL DEFAULT 'med',
        due_date DATE NULL,
        tags_json TEXT NOT NULL,
        created_by INT NOT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        FOREIGN KEY(created_by) REFERENCES users(id)
    );
INSERT INTO projects VALUES(2,'Apple-Style CRM v1','Ekip içi proje ve iş akışı takibi için ilk sürüm.','web','Internal','gelistiriliyor','high','2026-03-17','["crm","internal","v1"]',1,'2026-02-24 14:01:53','2026-02-24 14:01:53');
CREATE TABLE project_members (
        project_id INT NOT NULL,
        user_id INT NOT NULL,
        created_at DATETIME NOT NULL, member_status VARCHAR(40) NOT NULL DEFAULT 'baslaniyor',
        PRIMARY KEY(project_id, user_id),
        FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE,
        FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
    );
INSERT INTO project_members VALUES(2,1,'2026-02-24 14:01:53','gelistiriliyor');
CREATE TABLE stages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        project_id INT NOT NULL,
        name VARCHAR(120) NOT NULL,
        order_index INT NOT NULL DEFAULT 0,
        status VARCHAR(32) NOT NULL DEFAULT 'not_started',
        assignees_json TEXT NOT NULL,
        est_hours DOUBLE NULL,
        spent_hours DOUBLE NULL,
        links_json TEXT NOT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE
    );
INSERT INTO stages VALUES(8,2,'Brief ve Hedef',0,'baslanmadi','[1]',8.0,0.0,'[]','2026-02-24 14:01:53','2026-02-24 14:01:53');
INSERT INTO stages VALUES(9,2,'Araştırma',1,'baslanmadi','[1]',8.0,0.0,'[]','2026-02-24 14:01:53','2026-02-24 14:01:53');
INSERT INTO stages VALUES(10,2,'Üzerinde Tartışma',2,'baslanmadi','[1]',8.0,0.0,'[]','2026-02-24 14:01:53','2026-02-24 14:01:53');
INSERT INTO stages VALUES(11,2,'Tasarım',3,'baslanmadi','[1]',8.0,0.0,'[]','2026-02-24 14:01:53','2026-02-24 14:01:53');
INSERT INTO stages VALUES(12,2,'Geliştirme',4,'baslanmadi','[1]',8.0,0.0,'[]','2026-02-24 14:01:53','2026-02-24 14:01:53');
INSERT INTO stages VALUES(13,2,'İç Test',5,'baslanmadi','[1]',8.0,0.0,'[]','2026-02-24 14:01:53','2026-02-24 14:01:53');
INSERT INTO stages VALUES(14,2,'Müşteri Onayı',6,'baslanmadi','[1]',8.0,0.0,'[]','2026-02-24 14:01:53','2026-02-24 14:01:53');
INSERT INTO stages VALUES(15,2,'Yayın',7,'baslanmadi','[1]',8.0,0.0,'[]','2026-02-24 14:01:53','2026-02-24 14:01:53');
INSERT INTO stages VALUES(16,2,'Bakım',8,'baslanmadi','[1]',8.0,0.0,'[]','2026-02-24 14:01:53','2026-02-24 14:01:53');
CREATE TABLE tasks (
        id INT AUTO_INCREMENT PRIMARY KEY,
        project_id INT NOT NULL,
        stage_id INT NULL,
        title VARCHAR(180) NOT NULL,
        description TEXT NULL,
        assignee_id INT NULL,
        status VARCHAR(32) NOT NULL DEFAULT 'todo',
        priority VARCHAR(20) NOT NULL DEFAULT 'med',
        due_date DATE NULL,
        order_index INT NOT NULL DEFAULT 0,
        checklist_json TEXT NOT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE,
        FOREIGN KEY(stage_id) REFERENCES stages(id) ON DELETE SET NULL,
        FOREIGN KEY(assignee_id) REFERENCES users(id) ON DELETE SET NULL
    );
INSERT INTO tasks VALUES(5,2,8,'Bilgi mimarisi çıkar','CRM ana akışları için IA dokümanı hazırla.',1,'arastiriliyor','high','2026-03-01',0,'["Dashboard kartları","Proje detay sekmeleri"]','2026-02-24 14:01:53','2026-02-24 14:01:53');
INSERT INTO tasks VALUES(6,2,8,'SSE akışını doğrula','İki sekmede canlı bildirim testi yap.',1,'test_ediliyor','med','2026-03-03',1,'["Event stream","Notification center"]','2026-02-24 14:01:53','2026-02-24 14:01:53');
CREATE TABLE comments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        entity_type VARCHAR(30) NOT NULL,
        entity_id INT NOT NULL,
        project_id INT NULL,
        user_id INT NOT NULL,
        content TEXT NOT NULL,
        created_at DATETIME NOT NULL,
        FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE
    );
INSERT INTO comments VALUES(1,'idea',2,NULL,1,'evet','2026-02-24 13:39:17');
CREATE TABLE ideas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(180) NOT NULL,
        description TEXT NULL,
        category VARCHAR(120) NULL,
        tags_json TEXT NOT NULL,
        impact INT NOT NULL DEFAULT 3,
        effort INT NOT NULL DEFAULT 3,
        status VARCHAR(30) NOT NULL DEFAULT 'idea',
        created_by INT NOT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        FOREIGN KEY(created_by) REFERENCES users(id)
    );
INSERT INTO ideas VALUES(3,'AI destekli görev öncelikleme','Görev yoğunluğuna göre öneri üreten mini asistan.','crm_automasyon','["ai","priority"]',5,3,'idea',1,'2026-02-24 14:01:53','2026-02-24 14:01:53');
INSERT INTO ideas VALUES(4,'Müşteri portal entegrasyonu','Müşteriye sadece gerekli proje bilgilerini açan sade görünüm.','urun_stratejisi','["portal","client"]',4,4,'researching',1,'2026-02-24 14:01:53','2026-02-24 14:01:53');
CREATE TABLE audit_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        actor_id INT NOT NULL,
        action VARCHAR(80) NOT NULL,
        entity_type VARCHAR(30) NOT NULL,
        entity_id INT NOT NULL,
        project_id INT NULL,
        meta_json TEXT NOT NULL,
        created_at DATETIME NOT NULL,
        FOREIGN KEY(actor_id) REFERENCES users(id),
        FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE
    );
INSERT INTO audit_logs VALUES(22,1,'delete','project',1,NULL,'{"title":"Apple-Style CRM v1"}','2026-02-24 13:30:32');
INSERT INTO audit_logs VALUES(23,1,'comment','idea',2,NULL,'{"comment_id":1}','2026-02-24 13:39:17');
INSERT INTO audit_logs VALUES(24,1,'delete','idea',2,NULL,'{"title":"Müşteri portal entegrasyonu"}','2026-02-24 13:39:27');
INSERT INTO audit_logs VALUES(25,1,'delete','idea',1,NULL,'{"title":"AI destekli görev öncelikleme"}','2026-02-24 13:39:29');
INSERT INTO audit_logs VALUES(26,1,'create','project',2,2,'{"title":"Apple-Style CRM v1"}','2026-02-24 14:01:53');
CREATE TABLE notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        type VARCHAR(80) NOT NULL,
        payload_json TEXT NOT NULL,
        is_read TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
    );
INSERT INTO notifications VALUES(1,1,'project_created','{"project_id":1,"title":"Apple-Style CRM v1","actor_id":1,"message":"Seed proje oluşturuldu.","created_at":"2026-02-24 07:15:39"}',0,'2026-02-24 07:15:39');
INSERT INTO notifications VALUES(2,1,'project_member_updated','{"project_id":1,"project_title":"Apple-Style CRM v1","target_user_id":1,"member_status":"tartisiliyor","actor_id":1,"recipient_ids":[1],"created_at":"2026-02-24 10:59:46"}',0,'2026-02-24 10:59:46');
INSERT INTO notifications VALUES(3,1,'stage_updated','{"project_id":1,"stage_id":1,"stage_name":"Araştırma","status":"baslaniyor","actor_id":1,"recipient_ids":[1],"created_at":"2026-02-24 11:54:28"}',0,'2026-02-24 11:54:28');
INSERT INTO notifications VALUES(4,1,'project_status_changed','{"project_id":1,"project_title":"Apple-Style CRM v1","status":"bakimda","actor_id":1,"recipient_ids":[1],"created_at":"2026-02-24 11:56:59"}',0,'2026-02-24 11:56:59');
INSERT INTO notifications VALUES(5,1,'task_assigned','{"project_id":1,"task_id":3,"title":"evte","assignee_id":1,"status":"tamamlandi","actor_id":1,"recipient_ids":[1],"created_at":"2026-02-24 11:59:48"}',0,'2026-02-24 11:59:48');
INSERT INTO notifications VALUES(6,1,'task_assigned','{"project_id":1,"task_id":4,"title":"evet","assignee_id":1,"status":"tamamlandi","actor_id":1,"recipient_ids":[1],"created_at":"2026-02-24 11:59:57"}',0,'2026-02-24 11:59:57');
INSERT INTO notifications VALUES(7,1,'project_status_changed','{"project_id":1,"project_title":"Apple-Style CRM v1","status":"tamamlandi","actor_id":1,"recipient_ids":[1],"created_at":"2026-02-24 12:03:22"}',0,'2026-02-24 12:03:22');
INSERT INTO notifications VALUES(8,1,'project_status_changed','{"project_id":1,"project_title":"Apple-Style CRM v1","status":"tasarlaniyor","actor_id":1,"recipient_ids":[1],"created_at":"2026-02-24 12:03:28"}',0,'2026-02-24 12:03:28');
INSERT INTO notifications VALUES(9,1,'project_status_changed','{"project_id":1,"project_title":"Apple-Style CRM v1","status":"yayina_hazir","actor_id":1,"recipient_ids":[1],"created_at":"2026-02-24 12:03:32"}',0,'2026-02-24 12:03:32');
INSERT INTO notifications VALUES(10,1,'project_member_updated','{"project_id":1,"project_title":"Apple-Style CRM v1","target_user_id":1,"member_status":"duraklatildi","actor_id":1,"recipient_ids":[1],"created_at":"2026-02-24 12:03:45"}',0,'2026-02-24 12:03:45');
INSERT INTO notifications VALUES(11,1,'stage_updated','{"project_id":1,"stage_id":1,"stage_name":"Araştırma","status":"arastiriliyor","actor_id":1,"recipient_ids":[1],"created_at":"2026-02-24 12:34:59"}',0,'2026-02-24 12:34:59');
INSERT INTO notifications VALUES(12,1,'project_status_changed','{"project_id":1,"project_title":"Apple-Style CRM v1","status":"duraklatildi","actor_id":1,"recipient_ids":[1],"created_at":"2026-02-24 12:49:22"}',0,'2026-02-24 12:49:22');
INSERT INTO notifications VALUES(13,1,'stage_updated','{"project_id":1,"stage_id":1,"stage_name":"Araştırma","status":"deleted","actor_id":1,"recipient_ids":[1],"created_at":"2026-02-24 13:29:38"}',0,'2026-02-24 13:29:38');
INSERT INTO notifications VALUES(14,1,'stage_updated','{"project_id":1,"stage_id":2,"stage_name":"Tasarım","status":"deleted","actor_id":1,"recipient_ids":[1],"created_at":"2026-02-24 13:29:42"}',0,'2026-02-24 13:29:42');
INSERT INTO notifications VALUES(15,1,'stage_updated','{"project_id":1,"stage_id":3,"stage_name":"Geliştirme","status":"deleted","actor_id":1,"recipient_ids":[1],"created_at":"2026-02-24 13:29:54"}',0,'2026-02-24 13:29:54');
INSERT INTO notifications VALUES(16,1,'stage_updated','{"project_id":1,"stage_id":4,"stage_name":"Test","status":"deleted","actor_id":1,"recipient_ids":[1],"created_at":"2026-02-24 13:29:56"}',0,'2026-02-24 13:29:56');
INSERT INTO notifications VALUES(17,1,'stage_updated','{"project_id":1,"stage_id":5,"stage_name":"Yayın","status":"deleted","actor_id":1,"recipient_ids":[1],"created_at":"2026-02-24 13:29:58"}',0,'2026-02-24 13:29:58');
INSERT INTO notifications VALUES(18,1,'stage_updated','{"project_id":1,"stage_id":6,"stage_name":"Bakım","status":"deleted","actor_id":1,"recipient_ids":[1],"created_at":"2026-02-24 13:30:01"}',0,'2026-02-24 13:30:01');
INSERT INTO notifications VALUES(19,1,'stage_updated','{"project_id":1,"stage_id":7,"stage_name":"Araştırma","status":"baslaniyor","actor_id":1,"recipient_ids":[1],"created_at":"2026-02-24 13:30:17"}',0,'2026-02-24 13:30:17');
INSERT INTO notifications VALUES(20,1,'task_status_changed','{"project_id":1,"task_id":1,"title":"Bilgi mimarisi çıkar","from_status":"backlog","to_status":"baslaniyor","actor_id":1,"recipient_ids":[1],"created_at":"2026-02-24 13:30:23"}',0,'2026-02-24 13:30:23');
INSERT INTO notifications VALUES(21,1,'project_status_changed','{"project_id":1,"project_title":"Apple-Style CRM v1","status":"deleted","actor_id":1,"recipient_ids":[1],"created_at":"2026-02-24 13:30:32"}',0,'2026-02-24 13:30:32');
INSERT INTO notifications VALUES(22,1,'comment_added','{"project_id":null,"entity_type":"idea","entity_id":2,"comment_id":1,"content_preview":"evet","actor_id":1,"recipient_ids":[1],"created_at":"2026-02-24 13:39:17"}',0,'2026-02-24 13:39:17');
INSERT INTO notifications VALUES(23,1,'project_created','{"project_id":2,"title":"Apple-Style CRM v1","actor_id":1,"message":"Seed proje oluşturuldu.","created_at":"2026-02-24 14:01:53"}',0,'2026-02-24 14:01:53');
CREATE TABLE events (
        id INT AUTO_INCREMENT PRIMARY KEY,
        type VARCHAR(80) NOT NULL,
        payload_json TEXT NOT NULL,
        created_at DATETIME NOT NULL
    );
INSERT INTO events VALUES(1,'project_created','{"project_id":1,"title":"Apple-Style CRM v1","actor_id":1,"message":"Seed proje oluşturuldu.","created_at":"2026-02-24 07:15:39"}','2026-02-24 07:15:39');
INSERT INTO events VALUES(2,'project_member_updated','{"project_id":1,"project_title":"Apple-Style CRM v1","target_user_id":1,"member_status":"tartisiliyor","actor_id":1,"recipient_ids":[1],"created_at":"2026-02-24 10:59:46"}','2026-02-24 10:59:46');
INSERT INTO events VALUES(3,'stage_updated','{"project_id":1,"stage_id":1,"stage_name":"Araştırma","status":"baslaniyor","actor_id":1,"recipient_ids":[1],"created_at":"2026-02-24 11:54:28"}','2026-02-24 11:54:28');
INSERT INTO events VALUES(4,'project_status_changed','{"project_id":1,"project_title":"Apple-Style CRM v1","status":"bakimda","actor_id":1,"recipient_ids":[1],"created_at":"2026-02-24 11:56:59"}','2026-02-24 11:56:59');
INSERT INTO events VALUES(5,'task_assigned','{"project_id":1,"task_id":3,"title":"evte","assignee_id":1,"status":"tamamlandi","actor_id":1,"recipient_ids":[1],"created_at":"2026-02-24 11:59:48"}','2026-02-24 11:59:48');
INSERT INTO events VALUES(6,'task_assigned','{"project_id":1,"task_id":4,"title":"evet","assignee_id":1,"status":"tamamlandi","actor_id":1,"recipient_ids":[1],"created_at":"2026-02-24 11:59:57"}','2026-02-24 11:59:57');
INSERT INTO events VALUES(7,'project_status_changed','{"project_id":1,"project_title":"Apple-Style CRM v1","status":"tamamlandi","actor_id":1,"recipient_ids":[1],"created_at":"2026-02-24 12:03:22"}','2026-02-24 12:03:22');
INSERT INTO events VALUES(8,'project_status_changed','{"project_id":1,"project_title":"Apple-Style CRM v1","status":"tasarlaniyor","actor_id":1,"recipient_ids":[1],"created_at":"2026-02-24 12:03:28"}','2026-02-24 12:03:28');
INSERT INTO events VALUES(9,'project_status_changed','{"project_id":1,"project_title":"Apple-Style CRM v1","status":"yayina_hazir","actor_id":1,"recipient_ids":[1],"created_at":"2026-02-24 12:03:32"}','2026-02-24 12:03:32');
INSERT INTO events VALUES(10,'project_member_updated','{"project_id":1,"project_title":"Apple-Style CRM v1","target_user_id":1,"member_status":"duraklatildi","actor_id":1,"recipient_ids":[1],"created_at":"2026-02-24 12:03:45"}','2026-02-24 12:03:45');
INSERT INTO events VALUES(11,'stage_updated','{"project_id":1,"stage_id":1,"stage_name":"Araştırma","status":"arastiriliyor","actor_id":1,"recipient_ids":[1],"created_at":"2026-02-24 12:34:59"}','2026-02-24 12:34:59');
INSERT INTO events VALUES(12,'project_status_changed','{"project_id":1,"project_title":"Apple-Style CRM v1","status":"duraklatildi","actor_id":1,"recipient_ids":[1],"created_at":"2026-02-24 12:49:22"}','2026-02-24 12:49:22');
INSERT INTO events VALUES(13,'stage_updated','{"project_id":1,"stage_id":1,"stage_name":"Araştırma","status":"deleted","actor_id":1,"recipient_ids":[1],"created_at":"2026-02-24 13:29:38"}','2026-02-24 13:29:38');
INSERT INTO events VALUES(14,'stage_updated','{"project_id":1,"stage_id":2,"stage_name":"Tasarım","status":"deleted","actor_id":1,"recipient_ids":[1],"created_at":"2026-02-24 13:29:42"}','2026-02-24 13:29:42');
INSERT INTO events VALUES(15,'stage_updated','{"project_id":1,"stage_id":3,"stage_name":"Geliştirme","status":"deleted","actor_id":1,"recipient_ids":[1],"created_at":"2026-02-24 13:29:54"}','2026-02-24 13:29:54');
INSERT INTO events VALUES(16,'stage_updated','{"project_id":1,"stage_id":4,"stage_name":"Test","status":"deleted","actor_id":1,"recipient_ids":[1],"created_at":"2026-02-24 13:29:56"}','2026-02-24 13:29:56');
INSERT INTO events VALUES(17,'stage_updated','{"project_id":1,"stage_id":5,"stage_name":"Yayın","status":"deleted","actor_id":1,"recipient_ids":[1],"created_at":"2026-02-24 13:29:58"}','2026-02-24 13:29:58');
INSERT INTO events VALUES(18,'stage_updated','{"project_id":1,"stage_id":6,"stage_name":"Bakım","status":"deleted","actor_id":1,"recipient_ids":[1],"created_at":"2026-02-24 13:30:01"}','2026-02-24 13:30:01');
INSERT INTO events VALUES(19,'stage_updated','{"project_id":1,"stage_id":7,"stage_name":"Araştırma","status":"baslaniyor","actor_id":1,"recipient_ids":[1],"created_at":"2026-02-24 13:30:17"}','2026-02-24 13:30:17');
INSERT INTO events VALUES(20,'task_status_changed','{"project_id":1,"task_id":1,"title":"Bilgi mimarisi çıkar","from_status":"backlog","to_status":"baslaniyor","actor_id":1,"recipient_ids":[1],"created_at":"2026-02-24 13:30:23"}','2026-02-24 13:30:23');
INSERT INTO events VALUES(21,'project_status_changed','{"project_id":1,"project_title":"Apple-Style CRM v1","status":"deleted","actor_id":1,"recipient_ids":[1],"created_at":"2026-02-24 13:30:32"}','2026-02-24 13:30:32');
INSERT INTO events VALUES(22,'comment_added','{"project_id":null,"entity_type":"idea","entity_id":2,"comment_id":1,"content_preview":"evet","actor_id":1,"recipient_ids":[1],"created_at":"2026-02-24 13:39:17"}','2026-02-24 13:39:17');
INSERT INTO events VALUES(23,'project_created','{"project_id":2,"title":"Apple-Style CRM v1","actor_id":1,"message":"Seed proje oluşturuldu.","created_at":"2026-02-24 14:01:53"}','2026-02-24 14:01:53');
CREATE TABLE stage_templates (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(120) NOT NULL,
        order_index INT NOT NULL,
        created_at DATETIME NOT NULL
    );
INSERT INTO stage_templates VALUES(11,'Brief ve Hedef',0,'2026-02-24 13:18:01');
INSERT INTO stage_templates VALUES(12,'Araştırma',1,'2026-02-24 13:18:01');
INSERT INTO stage_templates VALUES(13,'Üzerinde Tartışma',2,'2026-02-24 13:18:01');
INSERT INTO stage_templates VALUES(14,'Tasarım',3,'2026-02-24 13:18:01');
INSERT INTO stage_templates VALUES(15,'Geliştirme',4,'2026-02-24 13:18:01');
INSERT INTO stage_templates VALUES(16,'İç Test',5,'2026-02-24 13:18:01');
INSERT INTO stage_templates VALUES(17,'Müşteri Onayı',6,'2026-02-24 13:18:01');
INSERT INTO stage_templates VALUES(18,'Yayın',7,'2026-02-24 13:18:01');
INSERT INTO stage_templates VALUES(19,'Bakım',8,'2026-02-24 13:18:01');
CREATE TABLE login_attempts (
        ip VARCHAR(64) PRIMARY KEY,
        failed_count INT NOT NULL DEFAULT 0,
        first_failed_at DATETIME NOT NULL,
        lock_until DATETIME NULL
    );
CREATE INDEX idx_projects_status_updated ON projects(status, updated_at);
CREATE INDEX idx_projects_created_by ON projects(created_by);
CREATE INDEX idx_project_members_user_project ON project_members(user_id, project_id);
CREATE INDEX idx_project_members_project_status ON project_members(project_id, member_status);
CREATE INDEX idx_stages_project_order ON stages(project_id, order_index);
CREATE INDEX idx_stages_project_status ON stages(project_id, status);
CREATE INDEX idx_tasks_project_status_order ON tasks(project_id, status, order_index);
CREATE INDEX idx_tasks_assignee_status ON tasks(assignee_id, status);
CREATE INDEX idx_tasks_stage ON tasks(stage_id);
CREATE INDEX idx_tasks_due_date ON tasks(due_date);
CREATE INDEX idx_comments_entity ON comments(entity_type, entity_id);
CREATE INDEX idx_comments_project ON comments(project_id);
CREATE INDEX idx_comments_user ON comments(user_id);
CREATE INDEX idx_audit_project_created ON audit_logs(project_id, created_at);
CREATE INDEX idx_audit_actor_created ON audit_logs(actor_id, created_at);
CREATE INDEX idx_notifications_user_read_created ON notifications(user_id, is_read, created_at);
CREATE INDEX idx_notifications_user_id ON notifications(user_id, id);
CREATE INDEX idx_events_created ON events(created_at);
CREATE INDEX idx_ideas_status_updated ON ideas(status, updated_at);
CREATE INDEX idx_invites_token_expires ON invites(token, expires_at);
CREATE UNIQUE INDEX idx_users_username_unique ON users(username);

SET FOREIGN_KEY_CHECKS=1;
