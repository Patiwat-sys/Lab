# LabOps Modern (SQLite Edition)

โปรเจกต์นี้คือเวอร์ชันปรับปรุงใหม่ของระบบห้องปฏิบัติการ Coal/Limestone เดิม โดยย้ายมาใช้ SQLite และออกแบบ UI ใหม่ให้ทันสมัย รองรับการใช้งานหลายอุปกรณ์

## สิ่งที่เปลี่ยนจากระบบเดิม

1. ย้ายการเก็บข้อมูลจากไฟล์ text มาเป็น SQLite
2. ถอดระบบ Prediction Model ออกทั้งหมด
3. รีดีไซน์ UI ใหม่ทั้งระบบในโทนฟ้าแบบมินิมอล
4. เพิ่มระบบสมาชิก (Login + Role)
5. เพิ่มระบบบันทึกกิจกรรมสำคัญ (Activity Logs)
6. เพิ่มโมดูลใหม่ Gas Consumption
7. รวมส่วนลิงก์ไว้ในหน้า Dashboard (ไม่ใช้หน้าลิงก์แยกสำหรับงานประจำ)
8. รวมหน้า History เข้าไปใน Data Management

## ฟีเจอร์หลักของระบบ

1. Dashboard
- เลย์เอาต์ภาพรวมแบบใหม่ (ไม่ยึดรูปแบบ 3 คอลัมน์เดิม)
- มี Operations Pulse แสดงค่าสรุปสำคัญ เช่น ยอดรายเดือน, จำนวนสมาชิก, จำนวน logs วันนี้
- มี Links Center ด้านล่าง แยก Coal และ Limestone

2. Coal Dashboard
- แสดงค่าล่าสุดตามช่วงเวลา: today, this month, this year, last month, last year
- ตัวชี้วัด: sample, TM, ASH, CV, S
- มีกราฟแท่ง Incoming Sample พร้อมเลือกสรุปได้ 3 แบบ:
  - Day (ค่าเริ่มต้น)
  - Week
  - Month
- มีตัวเลขแสดงบนแท่งกราฟ

3. Limestone Dashboard
- แสดงค่าล่าสุดตามช่วงเวลา
- ตัวชี้วัด: sample, M Power, CaCO3 Power, M Auto, CaCO3 Auto
- มีกราฟแท่ง Incoming Sample พร้อมเลือกสรุปได้ 3 แบบ:
  - Day (ค่าเริ่มต้น)
  - Week
  - Month
- มีตัวเลขแสดงบนแท่งกราฟ

4. Gas Consumption
- บันทึกการใช้แก๊สตามวันที่, รหัสถัง, ชนิดแก๊ส, ค่าเปิด/ปิด, ปริมาณใช้, หมายเหตุ
- แสดงรายการล่าสุด

5. Data Management (เฉพาะ admin)
- ใช้แทนชื่อเดิม Admin
- รวม 2 แท็บในหน้าเดียว:
  - Manage Data: แก้ไขข้อมูล Coal/Limestone และลิงก์
  - History: ดูข้อมูลย้อนหลัง Coal/Limestone/Gas

6. Member System
- Login/Logout ด้วย session
- สิทธิ์ตามบทบาท:
  - admin: เข้าถึง Data Management, Members, Logs ได้ทั้งหมด
  - member: เข้าถึงหน้าการทำงานทั่วไป

7. Activity Logs
- บันทึกกิจกรรมสำคัญ เช่น LOGIN, LOGOUT, CREATE, UPDATE
- เก็บ user, module, detail, IP, timestamp

## เมนูปัจจุบัน (Sidebar ซ้าย)

- Dashboard
- Coal
- Limestone
- Gas Consumption
- Data Management (admin)
- Members (admin)
- Logs (admin)

หมายเหตุ:
- เมนู Links ถูกเอาออกจาก sidebar และรวมไว้ใน Dashboard
- เมนู History ถูกเอาออกจาก sidebar และรวมไว้ใน Data Management

## หน้าหลักในระบบ

- login.php: หน้าเข้าสู่ระบบแบบ animated พร้อมตัวเลือกจำค่าที่พิมพ์
- dashboard.php: ภาพรวมระบบ + links center
- coal_dashboard.php: ค่าคุณภาพถ่านหิน + กราฟ incoming sample
- limestone_dashboard.php: ค่าคุณภาพหินปูน + กราฟ incoming sample
- gas_consumption.php: บันทึกและดูข้อมูลการใช้แก๊ส
- admin.php: Data Management (2 แท็บ: Manage Data / History)
- members.php: จัดการสมาชิก (admin)
- activity_logs.php: ดูบันทึกกิจกรรม (admin)

หน้าที่ยังรองรับลิงก์เก่า:
- index.php -> dashboard.php#links-center
- history.php -> admin.php?tab=history&module=...

## หมายเหตุหน้า Login

- หน้า Login มี animation พื้นหลังและการเคลื่อนไหวขององค์ประกอบ
- รองรับการจำ username/password ที่เคยกรอกไว้ใน localStorage (เปิด/ปิดได้)

## โครงสร้างฐานข้อมูล

ไฟล์ SQLite:
- storage/lab.sqlite

ตารางหลัก:
- users
- coal_records
- limestone_records
- gas_consumption
- external_links
- activity_logs

## การย้ายข้อมูลจากระบบเดิม (Migration)

ตอนรันครั้งแรก ระบบจะ seed ข้อมูลอัตโนมัติจาก:
- data.txt -> coal_records, limestone_records
- link.txt -> external_links

การ seed จะทำเฉพาะกรณีที่ตารางปลายทางยังไม่มีข้อมูล

## บัญชีเริ่มต้น

- Username: admin
- Password: admin123

สำคัญ: ควรเปลี่ยนรหัสผ่านนี้ทันทีเมื่อเริ่มใช้งานจริง

## วิธีรัน (XAMPP)

1. วางโปรเจกต์ไว้ใน htdocs
2. เปิด Apache ใน XAMPP
3. ตรวจสอบว่า PHP SQLite extension เปิดใช้งานอยู่
4. เข้าใช้งานผ่าน:
- http://localhost/2026/lab/

index.html จะ redirect ไป login.php อัตโนมัติ

## หมายเหตุความเข้ากันได้กับไฟล์เดิม

- connect.php ปรับให้เรียกใช้งาน SQLite bootstrap
- edit_link.php redirect ไป admin.php
- coal/list.php redirect ไป history.php?module=coal (และไปต่อที่ Data Management > History)
- index.html redirect ไป login.php

## ข้อเสนอแนะด้านความปลอดภัยและการใช้งานจริง

1. ใช้รหัสผ่านที่รัดกุม และลบบัญชี default ก่อนใช้งานจริง
2. ป้องกันการเข้าถึงไฟล์ storage/lab.sqlite ระดับเซิร์ฟเวอร์
3. ควรใช้งานผ่าน HTTPS และเพิ่ม CSRF protection ใน production
4. หากข้อมูลย้อนหลังหรือ logs มาก ควรเพิ่ม pagination และตัวกรองเพิ่มเติม
