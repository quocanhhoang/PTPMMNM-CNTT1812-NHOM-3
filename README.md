# FileShare

Ứng dụng PHP quản lý tệp riêng tư, tài khoản người dùng và liên kết tải xuống có kiểm soát.

## Chạy ứng dụng

- Cần PHP 8.1 trở lên với các extension `fileinfo`, `session` và `pgsql`.
- Đăng nhập/đăng ký sử dụng bảng PostgreSQL `public.users` đã có sẵn; các migration bên dưới bổ sung những cột và bảng ứng dụng cần.
- Bảng `public.users` cần có sẵn các cột `id`, `username`, `full_name`, `email`, `status` và `storage_limit`. Migration `001_accounts_folders_quota.sql` bổ sung `password_hash`, `storage_quota_bytes` và cấu trúc thư mục; `003_admin_notifications.sql` bổ sung role và thông báo.
- Cấu hình kết nối bằng các biến môi trường `DB_HOST` (mặc định `localhost`), `DB_PORT` (mặc định `5432`), `DB_NAME` (mặc định `fileSharing`), `DB_USER` và `DB_PASSWORD`, hoặc tạo `config/database.local.php` từ `config/database.local.example.php` rồi điền thông tin PostgreSQL cục bộ. Tệp cấu hình cục bộ đã được Git bỏ qua; không commit hoặc chia sẻ mật khẩu. Nếu dùng Apache/PHP-FPM, có thể khai báo biến môi trường trong cấu hình service/pool rồi khởi động lại service.
- Cấu hình cục bộ: chạy `cp config/database.local.example.php config/database.local.php`, sau đó sửa `config/database.local.php` với host, database, user và mật khẩu PostgreSQL thật.
- Ví dụ chạy development từ cùng terminal trên Ubuntu; lệnh `read` nhận mật khẩu trực tiếp, không echo mật khẩu ra màn hình:

  ```sh
  export DB_HOST=localhost DB_PORT=5432 DB_NAME=fileSharing DB_USER=your_postgres_user
  read -rsp "PostgreSQL password: " DB_PASSWORD; export DB_PASSWORD; echo
  php -S localhost:8000
  ```

- Với Apache/PHP-FPM, đặt các biến trên trong môi trường service/pool PHP một cách an toàn rồi reload service; không đặt mật khẩu vào URL hay repository.
- Chạy từ thư mục dự án bằng `php -S localhost:8000`.
- Mở `http://localhost:8000`. Tạo tài khoản mới hoặc đăng nhập; mật khẩu tối thiểu 10 ký tự.
- Mỗi tài khoản có thư mục mặc định và hạn mức 10 GB. Có thể đặt `FILESHARE_DATA_DIR` để chọn thư mục dữ liệu riêng nằm ngoài document root.
- Tệp được giới hạn 100 MB mặc định. Nếu dùng PHP built-in server, cấu hình `upload_max_filesize` và `post_max_size` trong `php.ini` đủ lớn (ví dụ 100M và 105M).
- Chỉ chấp nhận PDF, TXT, CSV, PNG, JPG/JPEG, GIF và WEBP; MIME thực tế được xác minh phía máy chủ.
- Metadata người dùng/tệp/liên kết được lưu trong PostgreSQL. Nội dung tải lên nằm trong `FILESHARE_STORAGE_DIR/<user_id>/<folder_id>/` với tên ngẫu nhiên; ứng dụng từ chối storage nằm trong document root, giới hạn quyền thư mục ở `0700` và quyền tệp ở `0600`. Cần bảo đảm tài khoản chạy PHP có quyền ghi `FILESHARE_STORAGE_DIR`. Mỗi lần tải, máy chủ kiểm tra trạng thái liên kết; với tệp riêng tư, chỉ chủ sở hữu hoặc người có bản ghi trong `file_permissions` được tải. Dữ liệu phụ trợ JSON và khóa đồng bộ dùng `FILESHARE_DATA_DIR` (mặc định cũng nằm ngoài document root).
- Khi triển khai sau reverse proxy hoặc dùng domain cố định, đặt `FILESHARE_BASE_URL` (ví dụ `https://files.example.com`) để liên kết chia sẻ luôn trỏ đúng địa chỉ HTTPS.
- Tài khoản mới được mở đăng ký tự do. Đưa ứng dụng lên production cần HTTPS và thiết lập giới hạn tải phù hợp trên web server.
- Đăng ký ghi trực tiếp vào `public.users`: `username` được tạo từ phần trước `@` của email kèm hậu tố ngẫu nhiên, tên lưu vào `full_name`, email lưu vào `email`, hạn mức lưu vào `storage_limit`, và mật khẩu chỉ lưu dưới dạng `password_hash()`. Đăng nhập chỉ chấp nhận tài khoản có `status = 'active'`. Các API quản lý tệp cũng lọc theo ID tài khoản đang đăng nhập thay vì ID chủ sở hữu cố định.
- Liên kết công khai được lưu trên bản ghi `public.files`, gồm mã chia sẻ, thời hạn và giới hạn tải. Các migration `001_file_link_password.sql` và `002_link_password.sql` bổ sung hỗ trợ mật khẩu liên kết; ứng dụng chỉ lưu password hash và URL tải qua `download.php` để kiểm tra mật khẩu, thời hạn và lượt tải.
- Chạy các migration dưới đây bằng role có quyền ALTER TABLE theo đúng thứ tự liệt kê. Hai migration thư mục tạo cấu trúc thư mục phân cấp, tạo thư mục mặc định cho tài khoản hiện có và chuyển tệp cũ vào đó:
  `001_accounts_folders_quota.sql`, `001_file_link_password.sql`, `002_file_cleanup.sql`, `002_link_password.sql`, `003_admin_notifications.sql`, `003_file_folders.sql`.
- Với cấu hình hiện tại là role `nhat`, chạy lệnh dưới đây trong WSL tại thư mục dự án để cài migration và cấp quyền cần thiết:
  ```sh
  sudo -u postgres psql -d fileSharing -v ON_ERROR_STOP=1 -f database/migrations/001_accounts_folders_quota.sql -f database/migrations/001_file_link_password.sql -f database/migrations/002_file_cleanup.sql -f database/migrations/002_link_password.sql -f database/migrations/003_admin_notifications.sql -f database/migrations/003_file_folders.sql -c "GRANT SELECT, INSERT, UPDATE ON public.users TO nhat; GRANT SELECT, INSERT, UPDATE, DELETE ON public.folders TO nhat; GRANT SELECT, INSERT, UPDATE, DELETE ON public.files TO nhat; GRANT SELECT, INSERT ON public.activity_logs, public.file_permissions TO nhat; GRANT SELECT, INSERT, UPDATE ON public.notifications TO nhat; GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO nhat;"
  ```
  Nếu `DB_USER` khác `nhat`, thay `nhat` bằng đúng role trong `config/database.local.php`. Tài khoản `postgres` cần quyền sở hữu/cấp quyền các bảng.
- Trong mục **Tệp của tôi**, chọn **+ Thư mục** để thêm thư mục. Thư mục sẽ xuất hiện trong bộ chọn **Thư mục lưu** khi tải tệp lên và trong bộ lọc danh sách tệp.
- Trong giao diện, tải tệp lên hoặc chọn tệp trong **Tệp của tôi**, mở **Liên kết chia sẻ** rồi chọn **+ Tạo liên kết**. Có thể đặt số ngày hiệu lực (1–3650), giới hạn lượt tải (để trống nếu không giới hạn) và mật khẩu tùy chọn. Danh sách hiển thị các liên kết còn hiệu lực, số lượt đã dùng và ngày hết hạn; mỗi tệp có một liên kết hiện hành, việc tạo lại liên kết sẽ vô hiệu hóa URL cũ.
- Trong **Tệp của tôi**, tìm theo tên tệp/thư mục và lọc theo trạng thái liên kết (đang chia sẻ, riêng tư, hết hạn/đã thu hồi hoặc chưa chia sẻ). **Xuất báo cáo** tải lịch sử từ `activity_logs` thành CSV; lượt tải thành công cũng được ghi nhận. Tệp có metadata nhưng nội dung trên ổ đĩa bị mất sẽ được đánh dấu/cảnh báo trong danh sách và lịch sử. Schema nhật ký hiện tại không lưu địa chỉ IP nên cột IP trong báo cáo sẽ để trống.

## Dọn tệp không còn liên kết hiệu lực

Tệp có liên kết đã hết hạn, bị thu hồi hoặc đã đạt giới hạn tải sẽ được đưa vào danh sách dọn dẹp. Nội dung tệp được xóa khỏi kho riêng; metadata được đánh dấu xóa mềm để giữ lịch sử.

1. Đặt các biến môi trường PostgreSQL giống lúc chạy ứng dụng.
2. Xem trước danh sách: `php cleanup.php --preview`
3. Chạy `php cleanup.php --execute`, kiểm tra lại danh sách được in ra và nhập `XOA` để xác nhận.

Lệnh chỉ chạy trên CLI, không thể gọi qua web. Nút **Xem trước tệp hết hạn** trong ứng dụng chỉ liệt kê ứng viên; việc xóa luôn cần lệnh CLI và xác nhận tương tác. Hoạt động dọn dẹp được ghi vào `activity_logs`.
