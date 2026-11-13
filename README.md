# FileShare

Ứng dụng PHP quản lý tệp riêng tư, tài khoản người dùng và liên kết tải xuống có kiểm soát.

<<<<<<< HEAD
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
- Trong mục **Nâng cấp gói**, người dùng có thể chuyển lên tổng hạn mức 5 GB, 10 GB (150.000đ) hoặc 100 GB (5.000.000đ). Chọn gói có dung lượng cao hơn sẽ cập nhật ngay giới hạn lưu trữ; thanh toán chưa được tích hợp và hiện chưa thu phí. Máy chủ cũng kiểm tra quota trước khi lưu tệp.
- Menu ba chấm cạnh tài khoản ở cuối thanh bên mở **Thông tin cá nhân** (tên, email, dung lượng và số tệp) hoặc **Cài đặt người dùng**. Cài đặt giao diện và quyền chia sẻ mặc định được lưu trên trình duyệt hiện tại.
- Nếu nâng cấp báo thiếu quyền PostgreSQL, cấp quyền cập nhật hạn mức cho đúng role `DB_USER` (ví dụ role `nhat`):
  ```sh
  sudo -u postgres psql -d fileSharing -v ON_ERROR_STOP=1 -c "GRANT UPDATE ON TABLE public.users TO nhat;"
  ```
- Tệp được giới hạn 100 MB mặc định. Nếu dùng PHP built-in server, chạy `php -d upload_max_filesize=100M -d post_max_size=105M -S localhost:8000`; nếu dùng Apache/PHP-FPM, đặt hai giá trị này trong cấu hình PHP và khởi động lại dịch vụ.
- Chỉ chấp nhận PDF, TXT, CSV, PNG, JPG/JPEG, GIF và WEBP; MIME thực tế được xác minh phía máy chủ.
- Metadata người dùng/tệp/liên kết được lưu trong PostgreSQL. Nội dung tải lên nằm trong `FILESHARE_STORAGE_DIR/<user_id>/<folder_id>/` với tên ngẫu nhiên; ứng dụng từ chối storage nằm trong document root, giới hạn quyền thư mục ở `0700` và quyền tệp ở `0600`. Cần bảo đảm tài khoản chạy PHP có quyền ghi `FILESHARE_STORAGE_DIR`. Mỗi lần tải, máy chủ kiểm tra trạng thái liên kết; với tệp riêng tư, chỉ chủ sở hữu hoặc người có bản ghi trong `file_permissions` được tải. Dữ liệu phụ trợ JSON và khóa đồng bộ dùng `FILESHARE_DATA_DIR` (mặc định cũng nằm ngoài document root).
- Khi triển khai sau reverse proxy hoặc dùng domain cố định, đặt `FILESHARE_BASE_URL` (ví dụ `https://files.example.com`) để liên kết chia sẻ luôn trỏ đúng địa chỉ HTTPS.
- Tài khoản mới được mở đăng ký tự do. Đưa ứng dụng lên production cần HTTPS và thiết lập giới hạn tải phù hợp trên web server.
- Đăng ký ghi trực tiếp vào `public.users`: `username` được tạo từ phần trước `@` của email kèm hậu tố ngẫu nhiên, tên lưu vào `full_name`, email lưu vào `email`, hạn mức lưu vào `storage_limit`, và mật khẩu chỉ lưu dưới dạng `password_hash()`. Đăng nhập chỉ chấp nhận tài khoản có `status = 'active'`. Các API quản lý tệp cũng lọc theo ID tài khoản đang đăng nhập thay vì ID chủ sở hữu cố định.
- Liên kết công khai được lưu trên bản ghi `public.files`, gồm mã chia sẻ, thời hạn và giới hạn tải. Các migration `001_file_link_password.sql` và `002_link_password.sql` bổ sung hỗ trợ mật khẩu liên kết; ứng dụng chỉ lưu password hash và URL tải qua `download.php` để kiểm tra mật khẩu, thời hạn và lượt tải.
- Chạy các migration dưới đây bằng role có quyền ALTER TABLE theo đúng thứ tự liệt kê. Hai migration thư mục tạo cấu trúc thư mục phân cấp, tạo thư mục mặc định cho tài khoản hiện có và chuyển tệp cũ vào đó:
  `001_accounts_folders_quota.sql`, `001_file_link_password.sql`, `002_file_cleanup.sql`, `002_link_password.sql`, `003_admin_notifications.sql`, `003_file_folders.sql`, `004_folder_hierarchy.sql`.
- Với cấu hình hiện tại là role `nhat`, chạy lệnh dưới đây trong WSL tại thư mục dự án để cài migration và cấp quyền cần thiết:
  ```sh
  sudo -u postgres psql -d fileSharing -v ON_ERROR_STOP=1 -f database/migrations/001_accounts_folders_quota.sql -f database/migrations/001_file_link_password.sql -f database/migrations/002_file_cleanup.sql -f database/migrations/002_link_password.sql -f database/migrations/003_admin_notifications.sql -f database/migrations/003_file_folders.sql -f database/migrations/004_folder_hierarchy.sql -c "GRANT SELECT, INSERT, UPDATE ON public.users TO nhat; GRANT SELECT, INSERT, UPDATE, DELETE ON public.folders TO nhat; GRANT SELECT, INSERT, UPDATE, DELETE ON public.files TO nhat; GRANT SELECT, INSERT ON public.activity_logs, public.file_permissions TO nhat; GRANT SELECT, INSERT, UPDATE ON public.notifications TO nhat; GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO nhat;"
  ```
  Nếu `DB_USER` khác `nhat`, thay `nhat` bằng đúng role trong `config/database.local.php`. Tài khoản `postgres` cần quyền sở hữu/cấp quyền các bảng.
- Trong mục **Tệp của tôi**, chọn **+ Thư mục** để thêm thư mục. Thư mục sẽ xuất hiện trong bộ chọn **Thư mục lưu** khi tải tệp lên và trong bộ lọc danh sách tệp.
- Trong giao diện, tải tệp lên hoặc chọn tệp trong **Tệp của tôi**, mở **Liên kết chia sẻ** rồi chọn **+ Tạo liên kết**. Có thể đặt số ngày hiệu lực (1–3650), giới hạn lượt tải (để trống nếu không giới hạn) và mật khẩu tùy chọn. Danh sách hiển thị các liên kết còn hiệu lực, số lượt đã dùng và ngày hết hạn; mỗi tệp có một liên kết hiện hành, việc tạo lại liên kết sẽ vô hiệu hóa URL cũ.
- Trong **Tệp của tôi**, tìm theo tên tệp/thư mục và lọc theo trạng thái liên kết (đang chia sẻ, riêng tư, hết hạn/đã thu hồi hoặc chưa chia sẻ). **Xuất báo cáo** tải lịch sử từ `activity_logs` thành CSV; lượt tải thành công cũng được ghi nhận. Tệp có metadata nhưng nội dung trên ổ đĩa bị mất sẽ được đánh dấu/cảnh báo trong danh sách và lịch sử. Schema nhật ký hiện tại không lưu địa chỉ IP nên cột IP trong báo cáo sẽ để trống.
- Liên kết của tệp **Riêng tư** vẫn có thể sao chép và gửi cho người nhận, nhưng URL không thay thế quyền truy cập: người nhận phải đăng nhập đúng tài khoản đã được chủ sở hữu cấp quyền. Người chưa đăng nhập nhận HTTP 401; tài khoản chưa được cấp quyền nhận HTTP 403. Khi tạo lại liên kết, ứng dụng giữ nguyên chế độ riêng tư của tệp.

## Dọn tệp không còn liên kết hiệu lực

Tệp có liên kết đã hết hạn, bị thu hồi hoặc đã đạt giới hạn tải sẽ được đưa vào danh sách dọn dẹp. Nội dung tệp được xóa khỏi kho riêng; metadata được đánh dấu xóa mềm để giữ lịch sử.

1. Đặt các biến môi trường PostgreSQL giống lúc chạy ứng dụng.
2. Xem trước danh sách: `php cleanup.php --preview`
3. Chạy `php cleanup.php --execute`, kiểm tra lại danh sách được in ra và nhập `XOA` để xác nhận.

Lệnh chỉ chạy trên CLI, không thể gọi qua web. Nút **Xem trước tệp hết hạn** trong ứng dụng chỉ liệt kê ứng viên; việc xóa luôn cần lệnh CLI và xác nhận tương tác. Hoạt động dọn dẹp được ghi vào `activity_logs`.
=======
- Cấu hình kết nối PostgreSQL trong `config/database.php`.
- Áp dụng migration `database/migrations/001_accounts_folders_quota.sql` lên database trước khi chạy ứng dụng. Tài khoản mới mặc định có quota 10 GB, theo `users.storage_limit` hiện có.
- Áp dụng `database/migrations/003_admin_notifications.sql` để bật vai trò quản trị và lưu thông báo. Đặt tài khoản quản trị bằng `php bin/set_user_role.php email@example.com admin`; có thể trả về tài khoản thường bằng tham số `user`.
- Áp dụng `database/migrations/004_rewards.sql` để bật hệ thống xu, nhiệm vụ, thống kê online và dung lượng thưởng có thời hạn.
- Đăng nhập qua `login.php`; người dùng mới có thể đăng ký. Để bật mật khẩu cho tài khoản đã tồn tại, chạy `php bin/set_user_password.php email@example.com`. Đổi quota bằng `php bin/set_user_quota.php email@example.com 2048` (đơn vị MB; không thể đặt thấp hơn dung lượng đang dùng).
- Chạy ứng dụng qua PHP server với giới hạn đủ cho tệp 1 GB, ví dụ: `php -d upload_max_filesize=1024M -d post_max_size=1050M -d max_execution_time=300 -d max_input_time=300 -S 127.0.0.1:8000`, rồi mở `http://127.0.0.1:8000`.
- PHP cần bật các extension `pgsql` và `fileinfo`; cấu hình `upload_max_filesize` tối thiểu `1024M` và `post_max_size` lớn hơn kích thước tệp (ví dụ `1050M`). Nếu chạy sau Nginx/Apache hoặc proxy, cần tăng giới hạn body tương ứng; giới hạn ứng dụng tối đa là 1 GB.
- Trước khi gửi, giao diện kiểm tra phần mở rộng, kích thước và quota; API kiểm tra lại kích thước, MIME thực tế, quota và quyền sở hữu thư mục trước khi ghi. Định dạng mặc định là PDF, Office, TXT/CSV/MD, JPG/PNG/GIF/WEBP và ZIP. Khi thêm định dạng qua `FILESHARE_ALLOWED_EXTENSIONS`, cần bổ sung MIME tương ứng trong `api/upload.php`.
- Nội dung tệp được lưu bên ngoài thư mục web, mặc định ở thư mục `../<tên-thư-mục-dự-án>-storage/uploads`. Có thể đổi bằng `FILESHARE_STORAGE_DIR`; tài khoản chạy PHP cần quyền ghi vào thư mục này.
- `files` lưu metadata, `activity_logs` lưu hoạt động tải lên, và `file_permissions` lưu quyền chia sẻ theo email của tài khoản đã đăng ký.
- Trang **Tệp của tôi** hỗ trợ tìm theo tên, lọc theo quyền chia sẻ/trạng thái liên kết và trạng thái thiếu tệp. Chọn **Xuất báo cáo truy cập** để tải CSV từ `api/access_report.php`; báo cáo gồm các lượt tải và hoạt động đã ghi trong `activity_logs`.
- Nút tìm kiếm trên thanh trên cùng hoặc phím `Ctrl+K`/`Cmd+K` mở tìm kiếm nhanh theo tên tệp và thư mục. Menu tài khoản mở thông tin cá nhân; chế độ sáng/tối và quyền chia sẻ mặc định được lưu trên trình duyệt hiện tại.
- Mục **Nâng cấp gói** hỗ trợ tăng hạn mức lên 5 GB, 10 GB hoặc 100 GB khi gói mới lớn hơn hạn mức hiện tại. Thanh toán chưa được tích hợp; lựa chọn gói hiện chỉ cập nhật quota.
- Nút ba chấm trên tệp có thao tác **Chia sẻ** (mở chi tiết/cấp quyền) và **Xóa**. Trang **Được chia sẻ** liệt kê các tệp bạn đã cấp quyền trực tiếp hoặc bật liên kết; tạo liên kết mới sẽ làm mới danh sách này.
- Chức năng thư viện phía trình duyệt nằm riêng trong `assets/js/file-library.js`; API xuất báo cáo nằm riêng trong `api/access_report.php`. Nếu metadata còn trong PostgreSQL nhưng nội dung đã bị xóa hoặc ổ lưu trữ không truy cập được, danh sách hiển thị “Thiếu tệp” và thao tác tải sẽ nêu rõ nguyên nhân.
- Sau khi đăng nhập, người dùng có thể tạo thư mục lồng nhau, chọn thư mục cha, mở thư mục để xem tệp bên trong, kéo thả tệp/thư mục để sắp xếp, chuyển tệp giữa thư mục và xóa thư mục rỗng. Quota chỉ tính metadata tệp trong PostgreSQL.
- Trong trang **Chi tiết tệp**, có thể xem lịch sử cấp quyền và đặt ngày hết hạn, giới hạn tải. Lưu cài đặt sẽ tạo mã mới và đặt lượt tải về 0. Liên kết công khai không yêu cầu mật khẩu; chỉ chia sẻ liên kết với người được phép truy cập.
- Xem trước tệp có liên kết hết hạn, bị thu hồi hoặc hết lượt tải ở cuối trang. Dọn tệp theo hai bước: chạy `php bin/cleanup_expired.php` để xem trước; sau khi rà soát, chạy `php bin/cleanup_expired.php --delete` và nhập `XOA` để xác nhận. Lệnh xóa metadata, quyền cấp cho tệp và log gắn với tệp cùng nội dung trên ổ đĩa.
- Máy chủ xác minh liên kết còn hoạt động, hạn dùng và giới hạn tải trước khi gửi nội dung. Tệp riêng tư chỉ tải được khi người dùng đã đăng nhập và là chủ sở hữu hoặc có quyền trong `file_permissions`. File nội dung nằm ngoài thư mục web; `FILESHARE_STORAGE_DIR` bị từ chối nếu trỏ vào trong web root.
- Chuông thông báo hiển thị thông báo tải tệp lên. Thông báo thanh toán/hết hạn gói cần được ghi vào bảng `notifications` khi bổ sung luồng thanh toán/gói dung lượng tương ứng; hiện dự án chưa có các luồng này.
- Dashboard admin tại `admin.php` chỉ cho tài khoản có `users.role = 'admin'`; số liệu tổng quan, hoạt động gần đây, top lượt tải và biểu đồ lượt tải 7 ngày được lấy từ PostgreSQL.
- Dashboard admin có mục **Tra cứu người dùng**, tìm theo chuỗi con trong tên và hiển thị tên, email, vai trò.
- Mục **Nhiệm vụ & xu** thưởng 25 xu cho lần đăng nhập đầu tiên mỗi ngày, 50 xu vào Chủ nhật. Nhiệm vụ upload và tạo liên kết được tính riêng: 5 lần/ngày nhận 10 xu, 20 lần/tuần nhận 20 xu, 50 lần/tháng nhận 30 xu. Các mốc online 15/30/45/60/120/180/360 phút nhận 50 xu mỗi mốc; tiến độ lưu theo ngày và được reset theo ngày của PostgreSQL. Tab nền vẫn gửi heartbeat mỗi 30 giây; khoảng thời gian giữa hai heartbeat được tính tối đa 60 giây để tránh cộng dồn thời gian khi đóng trang.
- Xu có thể đổi dung lượng cộng thêm tạm thời: 1 tuần +500 MB/500 xu, 2 tuần +1 GB/950 xu; 1/3/6/9 tháng lần lượt +3/9/18/27 GB với giá 2.500/4.850/7.620/10.300 xu; 1 năm +20 GB/13.870 xu. Các gói được cộng vào quota cơ bản và từng gói hết hạn độc lập.
- Dashboard admin có biểu đồ miền top 10 theo xu, số nhiệm vụ đã nhận thưởng và tổng thời gian online; admin có thể tặng số xu tự nhập cho người đang nằm trong ít nhất một bảng top 10. Các thay đổi số dư được ghi vào sổ giao dịch xu.
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
- \i 'database/migrations/003_admin_notifications.sql': Áp dụng migration dashboard admin và thông báo
- \du: Xem danh sách bảng
- \d users: Xem cấu trúc bảng
- 'INSERT INTO users (...);': Thêm dữ liệu mẫu
- 'SELECT * FROM users;': Truy vấn dữ liệu. Sau khi truy vấn xong, muốn thoát nhấn nút 'q'
- \q: thoát postgre
# Chạy WEB PHP:
- php -S 127.0.0.1:8001
>>>>>>> origin
