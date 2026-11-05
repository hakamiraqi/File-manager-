class FolderItem {
  final int id;
  final String name;
  final int? parentId;
  final String? color;
  final bool isPublic;
  final String folderType;
  final String? researcherName;
  final String? degree;
  final String? supervisorName;
  final String? thesisTitle;
  final String? publisher;
  final String? digitalLibrary;
  final String? publicationYear;
  final String? university;
  final String? college;
  final String? department;
  final String? subject;
  final String? keywords;
  final String? abstract;
  final String? defenseDate;
  final String? pages;
  final String? iqdrReceipt;
  final String? language;

  FolderItem({
    required this.id,
    required this.name,
    required this.parentId,
    required this.color,
    required this.isPublic,
    this.folderType = 'files',
    this.researcherName,
    this.degree,
    this.supervisorName,
    this.thesisTitle,
    this.publisher,
    this.digitalLibrary,
    this.publicationYear,
    this.university,
    this.college,
    this.department,
    this.subject,
    this.keywords,
    this.abstract,
    this.defenseDate,
    this.pages,
    this.iqdrReceipt,
    this.language,
  });

  factory FolderItem.fromJson(Map<String, dynamic> json) {
    return FolderItem(
      id: json['id'] as int,
      name: json['name'] as String,
      parentId: json['parent_id'] as int?,
      color: json['color'] as String?,
      isPublic: json['is_public'] as bool? ?? false,
      folderType: json['folder_type'] as String? ?? 'files',
      researcherName: json['researcher_name'] as String?,
      degree: json['degree'] as String?,
      supervisorName: json['supervisor_name'] as String?,
      thesisTitle: json['thesis_title'] as String?,
      publisher: json['publisher'] as String?,
      digitalLibrary: json['digital_library'] as String?,
      publicationYear: json['publication_year'] as String?,
      university: json['university']?.toString(),
      college: json['college']?.toString(),
      department: json['department']?.toString(),
      subject: json['subject']?.toString(),
      keywords: json['keywords']?.toString(),
      abstract: json['abstract']?.toString(),
      defenseDate: json['defense_date']?.toString(),
      pages: json['pages']?.toString(),
      iqdrReceipt: json['iqdr_receipt']?.toString(),
      language: json['language'] as String?,
    );
  }
}
