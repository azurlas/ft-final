import Foundation
import PDFKit
import AppKit
let document = PDFDocument(url: URL(fileURLWithPath: CommandLine.arguments[1]))!
print("Pages: \(document.pageCount)")
let directory = CommandLine.arguments[2]
try FileManager.default.createDirectory(atPath: directory, withIntermediateDirectories: true)
for index in 0..<document.pageCount {
    let page = document.page(at:index)!
    let image = page.thumbnail(of: NSSize(width: 1600,height: 1200),for:.mediaBox)
    let data = NSBitmapImageRep(data:image.tiffRepresentation!)!.representation(using:.png,properties:[:])!
    try data.write(to:URL(fileURLWithPath:"\(directory)/page-\(index+1).png"))
    print(page.string ?? "")
}
