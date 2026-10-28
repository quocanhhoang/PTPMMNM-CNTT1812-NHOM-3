# Nhom-3
Học lập trình web bằng PHP

## Lưu tệp vào PostgreSQL

- Cấu hình kết nối PostgreSQL trong `config/database.php`.
- Áp dụng migration `database/migrations/001_accounts_folders_quota.sql` lên database trước khi chạy ứng dụng. Tài khoản mới mặc định có quota 10 GB, theo `users.storage_limit` hiện có.
- Áp dụng thêm `database/migrations/002_link_password.sql` để bật mật khẩu cho liên kết.
- Đăng nhập qua `login.php`; người dùng mới có thể đăng ký. Để bật mật khẩu cho tài khoản đã tồn tại, chạy `php bin/set_user_password.php email@example.com`. Đổi quota bằng `php bin/set_user_quota.php email@example.com 2048` (đơn vị MB; không thể đặt thấp hơn dung lượng đang dùng).
- Chạy ứng dụng qua PHP server, ví dụ: `php -S 127.0.0.1:8000`, rồi mở `http://127.0.0.1:8000`.
- PHP cần bật các extension `pgsql` và `fileinfo`; giới hạn tải lên của PHP (`upload_max_filesize`, `post_max_size`) cần cho phép tệp tối đa 100 MB.
- Trước khi gửi, giao diện kiểm tra phần mở rộng, kích thước và quota; API kiểm tra lại kích thước, MIME thực tế, quota và quyền sở hữu thư mục trước khi ghi. Định dạng mặc định là PDF, Office, TXT/CSV/MD, JPG/PNG/GIF/WEBP và ZIP. Khi thêm định dạng qua `FILESHARE_ALLOWED_EXTENSIONS`, cần bổ sung MIME tương ứng trong `api/upload.php`.
- Nội dung tệp được lưu bên ngoài thư mục web, mặc định ở thư mục `../<tên-thư-mục-dự-án>-storage/uploads`. Có thể đổi bằng `FILESHARE_STORAGE_DIR`; tài khoản chạy PHP cần quyền ghi vào thư mục này.
- `files` lưu metadata, `activity_logs` lưu hoạt động tải lên, và `file_permissions` lưu quyền chia sẻ theo email của tài khoản đã đăng ký.
- Trang **Tệp của tôi** hỗ trợ tìm theo tên, lọc theo quyền chia sẻ/trạng thái liên kết và trạng thái thiếu tệp. Chọn **Xuất báo cáo truy cập** để tải CSV từ `api/access_report.php`; báo cáo gồm các lượt tải và hoạt động đã ghi trong `activity_logs`.
- Chức năng thư viện phía trình duyệt nằm riêng trong `assets/js/file-library.js`; API xuất báo cáo nằm riêng trong `api/access_report.php`. Nếu metadata còn trong PostgreSQL nhưng nội dung đã bị xóa hoặc ổ lưu trữ không truy cập được, danh sách hiển thị “Thiếu tệp” và thao tác tải sẽ nêu rõ nguyên nhân.
- Sau khi đăng nhập, người dùng có thể tạo/xóa thư mục rỗng, chọn thư mục lúc tải lên, chuyển tệp giữa thư mục và xóa tệp. Quota chỉ tính metadata tệp trong PostgreSQL.
- Trong trang **Chi tiết tệp**, có thể xem lịch sử cấp quyền và đặt ngày hết hạn, giới hạn tải, mật khẩu liên kết. Lưu cài đặt sẽ tạo mã mới và đặt lượt tải về 0; mật khẩu có thể gỡ bằng hộp chọn tương ứng. Trang **Tệp của tôi** liệt kê các liên kết công khai đang còn hiệu lực.
- Dọn tệp hết hạn theo hai bước: chạy `php bin/cleanup_expired.php` để xem trước danh sách; sau khi rà soát, chạy `php bin/cleanup_expired.php --delete` và nhập `XOA` để xác nhận. Lệnh xóa metadata, quyền cấp cho tệp và log gắn với tệp cùng nội dung trên ổ đĩa.
- Máy chủ xác minh liên kết còn hoạt động, hạn dùng, giới hạn tải và mật khẩu trước khi gửi nội dung. Tệp riêng tư chỉ tải được khi người dùng đã đăng nhập và là chủ sở hữu hoặc có quyền trong `file_permissions`. File nội dung nằm ngoài thư mục web; `FILESHARE_STORAGE_DIR` bị từ chối nếu trỏ vào trong web root.
# Git và Github:
- git remote -v: Kiểm tra project đang liên kết với repo GitHub nào.
- git remote set-url origin https://github.com/quocanhhoang/PTPMMNM-CNTT1812-NHOM-3.git: Cập nhật remote sau - khi đổi tên repo
- git clone https://github.com/quocanhhoang/PTPMMNM-CNTT1812-NHOM-3.git: clone project từ github về máy
- git checkout -b <tên-branch>: tạo nhánh cá nhân
- git push -u origin <tên-branch>: Push branch lên github
- 'git checkout main' và 'git pull origin main': Cập nhật main
- git checkout <tên-branch>: Quay lại branch riêng
- git merge <main>: ghép code từ main vào branch cá nhân (chỉ dùng sau khi checkout về branch của mình)
# Docker:
- curl -fsSL https://get.docker.com | sudo SBX=1 sh: cài đặt
- docker --version: xem version
- sudo docker run hello-world: test docker (Hello from Docker! là thành công)
- sudo usermod -aG docker $USER: Cấp quyền Docker cho user (group phải xuất hiện docker)
- docker ps -a: xem container
- docker rm <id>: xoá container
- docker rmi <name>: xoá image
# Database
- sudo -u postgres psql: Đăng nhập PostgreSQL
- \du: Kiểm tra role
* Từ đoạn này phải dùng lệnh sudo -u postgres psql thì mới dùng được những lệnh trong postgre
- 'CREATE DATABASE "fileSharing";': Tạo database
- \c "fileSharing": Kết nối database
- \i 'database/migrations/001_accounts_folders_quota.sql': Áp dụng migration tài khoản, thư mục và quota (chạy từ thư mục gốc project)
- \i 'database/migrations/002_link_password.sql': Áp dụng migration mật khẩu liên kết
- \du: Xem danh sách bảng
- \d users: Xem cấu trúc bảng
- 'INSERT INTO users (...);': Thêm dữ liệu mẫu
- 'SELECT * FROM users;': Truy vấn dữ liệu. Sau khi truy vấn xong, muốn thoát nhấn nút 'q'
- \q: thoát postgre
# Chạy WEB PHP:
- php -S 127.0.0.1:8001
