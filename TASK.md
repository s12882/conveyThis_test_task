## Stack
- PHP + Laravel
- MySQL
- RabbitMQ
- Bootstrap + jQuery on FE
- Docker

### File Upload
- Implement an asynchronous file uploader (PDF, DOCX) via a web interface.
- File size limit (e.g., 10MB).
- Information about uploaded files must be stored in the MySQL database.

### Pages
- A page to upload a file.
- A separate page for managing uploaded files.
- The ability to view a list of uploaded files.
- The ability to delete files manually.

### Business logic

- 24 hrs time to live for files
- Send Email notification with RabbitMQ when file is deleted. Email is specified in .env
- Actual email sending mechanism (SMTP, etc.) is not required
- Send email also upon manual deletion of a file
