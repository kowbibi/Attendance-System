
-- Create database if it doesn't exist
CREATE DATABASE IF NOT EXISTS attendance_system;
USE attendance_system;

-- Create users table
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    first_name VARCHAR(50) NOT NULL,
    last_name VARCHAR(50) NOT NULL,
    role ENUM('admin', 'student') NOT NULL,
    student_id VARCHAR(20) NULL,
    course_id INT NULL,
    year_level INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Create courses table
CREATE TABLE IF NOT EXISTS courses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(20) UNIQUE NOT NULL,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Create attendance table
CREATE TABLE IF NOT EXISTS attendance (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    date DATE NOT NULL,
    time TIME NOT NULL,
    status ENUM('On Time', 'Late') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Create excuse_letters table
CREATE TABLE IF NOT EXISTS excuse_letters (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    date DATE NOT NULL,
    reason TEXT NOT NULL,
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Insert sample courses
INSERT INTO courses (code, name, description) VALUES
('CS101', 'Introduction to Computer Science', 'Basic programming concepts and algorithms'),
('CS102', 'Data Structures', 'Advanced data structures and algorithms'),
('MATH101', 'Calculus I', 'Differential and integral calculus'),
('ENG101', 'English Composition', 'Writing and communication skills');

-- Insert sample admin user
INSERT INTO users (username, password, first_name, last_name, role) VALUES
('admin', 'admin123', 'System', 'Administrator', 'admin');

-- Insert sample student users
INSERT INTO users (username, password, first_name, last_name, role, student_id, course_id, year_level) VALUES
('student1', 'password123', 'John', 'Doe', 'student', 'STU001', 1, 1),
('student2', 'password123', 'Jane', 'Smith', 'student', 'STU002', 1, 2),
('student3', 'password123', 'Mike', 'Johnson', 'student', 'STU003', 2, 1),
('student4', 'password123', 'Sarah', 'Wilson', 'student', 'STU004', 2, 2);

-- Insert sample attendance records
INSERT INTO attendance (user_id, date, time, status) VALUES
(2, '2024-01-15', '08:30:00', 'On Time'),
(2, '2024-01-16', '09:15:00', 'Late'),
(3, '2024-01-15', '08:45:00', 'On Time'),
(4, '2024-01-15', '08:20:00', 'On Time'),
(5, '2024-01-15', '09:30:00', 'Late');

-- Insert sample excuse letters
INSERT INTO excuse_letters (user_id, date, reason, status) VALUES
(2, '2024-01-14', 'Medical appointment', 'approved'),
(3, '2024-01-13', 'Family emergency', 'pending'),
(4, '2024-01-12', 'Transportation issues', 'rejected');
